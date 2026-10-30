<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Terminals\Services\DispenserFillService;
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
        private DispenserFillService $dispenserFillService,
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
        if (!isset($body['name']) && !isset($body['is_active']) && !isset($body['dispenser_low_threshold'])) {
            return $this->validationFailed($response, ['_base' => ['At least one field (name, is_active, dispenser_low_threshold) must be provided']]);
        }

        if (!$this->validator->validate($body, [
            'name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable'],
            // #955. Zero is allowed and means "warn only once the estimate is
            // used up"; a negative threshold would be a warning that can never
            // fire, which is worse than none because it looks configured.
            'dispenser_low_threshold' => ['nullable', 'integer', 'gte:0', 'lte:100000'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $terminal = $this->terminalsService->updateTerminal(
            $id,
            $body['name'] ?? null,
            isset($body['is_active']) ? filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN) : null,
            $adminId,
            isset($body['dispenser_low_threshold']) ? (int) $body['dispenser_low_threshold'] : null,
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

    /**
     * Record that the hopper was counted and now holds this many tokens (#955).
     *
     * The estimate is arithmetic rather than a sensor — the machine's *empty*
     * switch is a factory option this unit does not have — so a refill is the
     * only moment the count is ever known for certain, and this route is how
     * that certainty gets in.
     *
     * **An exact count, and nothing else.** No "added N" and no "filled to the
     * top" (owner decision 8): both build on a figure nobody has checked, and
     * the point of a refill is to put the estimate back on a known value.
     *
     * **Not an acknowledgement.** It clears no fault and commands nothing at
     * the machine: the device has no reset route, and a jam is cleared by a
     * power cycle (owner decision 3).
     *
     * No step-up gate. This mints no credential and reveals nothing; the two
     * endpoints above have one because they hand out a token.
     */
    public function recordDispenserRefill(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            // Zero is a legitimate count — a hopper emptied for maintenance —
            // so the floor is zero rather than one. The ceiling is there
            // because a typo of a member's card number into this field should
            // be a validation error rather than a green estimate for a hopper
            // with a hundred thousand tokens in it.
            'tokens' => ['required', 'integer', 'gte:0', 'lte:100000'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $fill = $this->dispenserFillService->recordRefill(
            $args['id'],
            (int) $body['tokens'],
            $request->getAttribute('admin_user_id'),
        );

        return $this->json($response, ['dispenser_fill' => $fill->toArray()]);
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

    /**
     * Every open anomaly for one terminal (ADR-0041 §4).
     *
     * The terminals list and the credentials board only carry a count, so a
     * marker there has nothing to acknowledge until the admin opens this —
     * it is what supplies the anomaly ids `acknowledgeAnomaly()` needs.
     */
    public function listAnomalies(Request $request, Response $response, array $args): Response
    {
        $anomalies = $this->terminalsService->listOpenAnomalies($args['id']);

        return $this->json($response, ['anomalies' => $anomalies]);
    }

    /**
     * Mark a detected anomaly as seen (ADR-0041 §4).
     *
     * No step-up gate. The two credential-minting endpoints have one because
     * they hand out a token; this one clears a notice and touches nothing an
     * attacker would want — and an alert that is awkward to dismiss is an alert
     * that gets ignored, which costs more than it protects.
     */
    public function acknowledgeAnomaly(Request $request, Response $response, array $args): Response
    {
        $acknowledged = $this->terminalsService->acknowledgeAnomaly(
            $args['id'],
            $args['anomalyId'],
            $request->getAttribute('admin_user_id'),
        );

        if (!$acknowledged) {
            return $this->json($response, [
                'error' => 'not_found',
                'message' => 'No open anomaly with that id for this terminal',
            ], 404);
        }

        return $this->json($response, ['message' => 'Terminal anomaly acknowledged']);
    }
}
