<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Terminal management for the admin panel.
 *
 * The two endpoints that *mint a credential* — enrolling a terminal and
 * rotating its token — require a fresh step-up (own password, own TOTP code)
 * on top of the session, the same gate the encryption keys carry (ADR-0036,
 * #395). A terminal token reads the member roster and writes transactions, so
 * issuing one from a session somebody walked away from is exactly the case the
 * step-up exists for. Renaming, deactivating and revoking do not mint anything
 * and stay on plain session auth: revocation must never be the harder path.
 */
class AdminController
{
    use JsonResponder;

    public function __construct(
        private TerminalsService $terminalsService,
        private Validator $validator,
        private StepUpAuthService $stepUpAuthService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params);

        // Support is_active filter with proper string-to-bool conversion
        $isActive = null;
        if (isset($params['is_active'])) {
            $isActive = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $result = $this->terminalsService->listTerminals($query->perPage, $query->offset, $isActive);

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'name' => ['required', 'string', 'max:100'],
            'device_id' => ['required', 'string'],
            'current_password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        try {
            $result = $this->terminalsService->createTerminal($body['name'], $body['device_id'], $adminId);
        } catch (DuplicateResourceException) {
            return $this->validationFailed($response, ['device_id' => ['Device ID already exists']]);
        }

        // Return api_token at top level (not inside terminal object)
        $terminalData = $result['terminal']->toArray();
        unset($terminalData['api_token']);

        return $this->json($response, [
            'terminal' => $terminalData,
            'api_token' => $result['plaintext_token'],
            'message' => 'Terminal created successfully. The API token will not be shown again.',
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $terminal = $this->terminalsService->getTerminal($id);

        return $this->json($response, ['terminal' => $terminal->toArray()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        // Require at least one updatable field
        if (!isset($body['name']) && !isset($body['is_active'])) {
            return $this->validationFailed($response, ['_base' => ['At least one field (name, is_active) must be provided']]);
        }

        if (!$this->validator->validate($body, [
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $terminal = $this->terminalsService->updateTerminal(
            $id,
            $body['name'] ?? null,
            isset($body['is_active']) ? filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) : null,
            $adminId,
        );

        return $this->json($response, ['terminal' => $terminal->toArray()]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->terminalsService->deleteTerminal($id, $adminId);

        return $this->json($response, ['message' => 'Terminal deactivated']);
    }

    public function rotateToken(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, ['current_password' => ['required', 'string']])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        $result = $this->terminalsService->rotateToken($id, $adminId);

        // Return api_token at top level (not inside terminal object)
        $terminalData = $result['terminal']->toArray();
        unset($terminalData['api_token']);

        return $this->json($response, [
            'terminal' => $terminalData,
            'api_token' => $result['plaintext_token'],
            // The old token keeps working until this one is entered at the
            // device (#395) — the operator has to be told that, or they will
            // read a still-selling terminal as a rotation that failed.
            'message' => 'Token rotated successfully. The new API token will not be shown again. '
                . 'The current token keeps working until the new one is used at the terminal for the first time.',
        ]);
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
            'message' => 'Re-enter your password (and TOTP code) to issue a terminal token',
        ], 401);

        return false;
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->terminalsService->revokeAccess($id, $adminId);

        return $this->json($response, ['message' => 'Terminal access revoked']);
    }
}
