<?php

declare(strict_types=1);

namespace App\Modules\Security\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Security\DTOs\EncryptionKeyDto;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\KeyRotationService;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin management of IBAN encryption keys (ADR-0036).
 *
 * Every mutation requires a fresh step-up credential (own password + own TOTP)
 * on top of the session: registering or activating a key changes what every
 * future IBAN is sealed under, and revoking one declares an incident. Listing
 * is plain session-auth — it exposes metadata and public keys only.
 */
class EncryptionKeysController
{
    use JsonResponder;

    public function __construct(
        private EncryptionKeyService $encryptionKeyService,
        private KeyRotationService $keyRotationService,
        private StepUpAuthService $stepUpAuthService,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'keys' => array_map(fn(EncryptionKeyDto $dto) => $dto->toArray(), $this->encryptionKeyService->listKeys()),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'public_key' => ['required', 'string', 'max:64'],
            'key_identifier' => ['required', 'string', 'max:100'],
            'current_password' => ['required', 'string'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $dto = $this->encryptionKeyService->register(
                (string) $body['public_key'],
                (string) $body['key_identifier'],
                $request->getAttribute('admin_user_id'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'invalid_key', 'message' => $e->getMessage()], 422);
        }

        return $this->json($response, ['key' => $dto->toArray()], 201);
    }

    public function activate(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $dto = $this->encryptionKeyService->activate($args['id'], $request->getAttribute('admin_user_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'unknown_key', 'message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => 'invalid_state', 'message' => $e->getMessage()], 409);
        }

        return $this->json($response, ['key' => $dto->toArray()]);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $dto = $this->encryptionKeyService->revoke(
                $args['id'],
                (bool) ($body['compromised'] ?? false),
                $request->getAttribute('admin_user_id'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'unknown_key', 'message' => $e->getMessage()], 404);
        }

        return $this->json($response, ['key' => $dto->toArray()]);
    }

    /**
     * Re-seal one batch of stored IBANs from a retiring key onto the active one
     * (#394). Called repeatedly by the rotation wizard until nothing is left.
     *
     * The private half of the *old* key travels in this body — the only thing
     * that can open what is being rotated away from. Like the export it arrives
     * per request, is validated against the registered public half, and is
     * wiped before the response is written; between two batches the server
     * holds no decryption capability at all.
     */
    public function rotateBatch(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, ['private_key' => ['required', 'string']])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $batch = $this->keyRotationService->rotateBatch(
                $args['id'],
                (string) $body['private_key'],
                $request->getAttribute('admin_user_id'),
            );
        } catch (\InvalidArgumentException $e) {
            // Wrong or malformed key: nothing was decrypted, so this is bad
            // input rather than a conflict with server state — same shape the
            // SEPA export uses for the same mistake.
            return $this->json($response, ['error' => 'private_key_mismatch', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => 'invalid_state', 'message' => $e->getMessage()], 409);
        }

        return $this->json($response, ['rotation' => $batch->toArray()]);
    }

    /**
     * Retire the old key once the backlog is drained (#394).
     *
     * No private key here: the zero-row check is a count, not a decryption.
     */
    public function completeRotation(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $dto = $this->keyRotationService->completeRotation($args['id'], $request->getAttribute('admin_user_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'unknown_key', 'message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['error' => 'invalid_state', 'message' => $e->getMessage()], 409);
        }

        return $this->json($response, ['key' => $dto->toArray()]);
    }

    /** Shared step-up gate; on failure fills $failed with the 401 response. */
    private function requireStepUp(Request $request, Response $response, array $body, ?Response &$failed): bool
    {
        $caller = $request->getAttribute('admin_user');

        if ($caller !== null && $this->stepUpAuthService->verify($caller, $body, $request)) {
            return true;
        }

        $failed = $this->json($response, [
            'error' => 'invalid_credentials',
            'message' => 'Re-enter your password (and TOTP code) to manage encryption keys',
        ], 401);

        return false;
    }
}
