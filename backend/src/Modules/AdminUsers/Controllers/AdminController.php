<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private AdminUsersService $adminUsersService,
        private Validator $validator,
        private StepUpAuthService $stepUpAuthService,
        private AdminInvitationService $invitationService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params, defaultPerPage: 20);

        $filters = [];
        if (isset($params['status'])) {
            $filters['status'] = $params['status']; // 'active' or 'inactive'
        } elseif (isset($params['filters']['is_active'])) {
            $isActive = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
            $filters['status'] = $isActive ? 'active' : 'inactive';
        }

        $result = $this->adminUsersService->listAdminUsers($query->perPage, $query->offset, $filters);

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');
        $caller = $request->getAttribute('admin_user');

        if (!$this->validator->validate($body, [
            'email' => ['required', 'email'],
            'display_name' => ['required', 'string', 'max:100'],
            'locale' => ['required', 'string', 'in:de,en,fr'],
            'roles' => ['nullable', 'array'],
            'current_password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $roles = [];
        if (array_key_exists('roles', $body)) {
            $roles = $this->parseRoles($body['roles']);
            if ($roles === null) {
                return $this->invalidRoles($response);
            }
        }

        if (!$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Re-enter your password to create a new admin user',
            ], 401);
        }

        // Check for duplicate email
        if ($this->adminUsersService->emailTakenByAnother($body['email'])) {
            return $this->validationFailed($response, ['email' => ['Email already exists']]);
        }

        $result = $this->adminUsersService->createAdminUser(
            $body['email'],
            $body['display_name'],
            $body['locale'],
            $adminId,
            $roles,
        );

        // No password in the response any more (migration 058). What comes back
        // is the invitation: when it expires, and the link itself — so an
        // installation whose mail is not working yet still has something to
        // hand over, which is the situation this endpoint used to solve by
        // returning a live password.
        return $this->json($response, [
            'admin' => $result['admin']->toArray(),
            'invitation' => $result['invitation']->toArray(),
        ], 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $admin = $this->adminUsersService->findAdminUserById($id);

        if (!$admin) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');
        $caller = $request->getAttribute('admin_user');

        // Add validation
        if (!$this->validator->validate($body, [
            'email' => ['nullable', 'email'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'locale' => ['nullable', 'string', 'in:de,en,fr'],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'current_password' => ['nullable', 'string'],
            'totp_code' => ['nullable', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        // Check email uniqueness if provided
        if (isset($body['email']) && $this->adminUsersService->emailTakenByAnother($body['email'], $id)) {
            return $this->validationFailed($response, ['email' => ['Email already exists']]);
        }

        $roles = null;
        if (array_key_exists('roles', $body)) {
            $roles = $this->parseRoles($body['roles']);
            if ($roles === null) {
                return $this->invalidRoles($response);
            }
        }

        // CONTEXT.md's Role entry: no account can change its own role set,
        // Admin included — a role change is always made by a *different*
        // Admin acting on the account. Checked server-side rather than left to
        // the panel hiding the control, since this is the id that decides
        // whether the caller keeps their own access, not a UI nicety.
        if ($roles !== null && $id === $adminId) {
            return $this->json($response, [
                'error' => 'cannot_edit_own_roles',
                'message' => 'You cannot change your own roles',
            ], 403);
        }

        // ADR-0044 rule 2: granting a role is minting authority, so it costs a
        // step-up — the same price `POST /admin-users` pays. Without this,
        // creating an admin needs a password and a fresh TOTP code while
        // *promoting* an existing Getränkewart to admin needs only a live
        // session, and the promotion is the quieter of the two since lifecycle
        // mail fires on creation.
        //
        // Gated on the role set *actually changing*, mirroring `PATCH
        // /profile`'s email gate: a display-name edit, or a save that re-sends
        // the roles the account already holds, must not demand a TOTP code.
        if ($roles !== null && $this->adminUsersService->rolesWouldChange($id, $roles)) {
            if (!is_array($caller) || !$this->stepUpAuthService->verify($caller, $body, $request)) {
                return $this->json($response, [
                    'error' => 'invalid_credentials',
                    'message' => 'Re-enter your password to change roles',
                ], 401);
            }
        }

        $admin = $this->adminUsersService->updateAdminUser($id, $body, $adminId);

        if (!$admin) {
            return $this->json($response, ['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        // After the rest of the update, so a refused role change cannot leave
        // the other fields written — and after the existence check, so roles
        // are never granted to an id that turned out not to exist.
        if ($roles !== null) {
            $this->adminUsersService->setRoles($id, $roles, $adminId);
            $admin = $this->adminUsersService->findAdminUserById($id);
        }

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    /**
     * A submitted role list, or `null` when it names something that is not a
     * role.
     *
     * Rejected rather than filtered. `AdminRole::fromValues()` drops what it
     * does not recognise, which is right for reading a stored row and wrong
     * for a request: a PATCH carrying `["kassenwart", "superuser"]` that
     * silently stored `["kassenwart"]` would answer 200 to a caller who
     * believes they granted something else. An empty list is refused for the
     * same reason it is refused in the service — an account with no role can
     * do nothing, and saying so is better than doing it.
     *
     * @return list<AdminRole>|null
     */
    private function parseRoles(mixed $submitted): ?array
    {
        if (!is_array($submitted) || $submitted === [] || array_is_list($submitted) === false) {
            return null;
        }

        $roles = [];
        foreach ($submitted as $value) {
            $role = is_string($value) ? AdminRole::tryFrom($value) : null;
            if ($role === null) {
                return null;
            }
            $roles[] = $role;
        }

        return $roles;
    }

    private function invalidRoles(Response $response): Response
    {
        return $this->validationFailed($response, [
            'roles' => ['Must be a non-empty list of: ' . implode(', ', AdminRole::values())],
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $admin = $this->adminUsersService->deactivateAdminUser($id, $adminId);

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function reactivate(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');

        $admin = $this->adminUsersService->reactivateAdminUser($id, $adminId);

        return $this->json($response, ['admin' => $admin->toArray()]);
    }

    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $caller = $request->getAttribute('admin_user');

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'current_password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => "Re-enter your password to reset this admin's password",
            ], 401);
        }

        $result = $this->adminUsersService->resetAdminPassword($id, $adminId);

        return $this->json($response, [
            'admin' => $result['admin']->toArray(),
            'password' => $result['password'],
        ]);
    }

    /**
     * POST /api/admin/admin-users/{id}/invitation — issue a replacement link.
     *
     * For the ordinary case: the first invitation went to spam, or expired
     * before anyone opened it. Any live invitation for the account is revoked
     * by the same call, so a resend never leaves two working links.
     *
     * Behind the same step-up as the cross-account password reset, and for the
     * same reason: this mints a credential for somebody else's account
     * (ADR-0044 rule 2). The service refuses an account that has already
     * accepted one — the invitation is an onboarding credential, not a second
     * password-reset channel that skips this gate.
     */
    public function resendInvitation(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $adminId = $request->getAttribute('admin_user_id');
        $caller = $request->getAttribute('admin_user');

        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'current_password' => ['required', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->stepUpAuthService->verify($caller, $body, $request)) {
            return $this->json($response, [
                'error' => 'invalid_credentials',
                'message' => 'Re-enter your password to send this admin a new invitation',
            ], 401);
        }

        $invitation = $this->invitationService->reissue($id, $adminId);

        return $this->json($response, ['invitation' => $invitation->toArray()], 201);
    }
}
