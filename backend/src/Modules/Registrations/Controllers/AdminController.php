<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Controllers;

use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Registrations\Services\RegistrationLinkService;
use App\Modules\Registrations\Services\RegistrationReviewService;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The review inbox's HTTP surface (#779, UC-A17).
 *
 * Pattern 006 thin: parse, validate, delegate. The one judgement call it makes
 * is about the attestation on {@see approve()}, and it is a judgement about
 * *shape* rather than about policy — the refusal itself lives in the service,
 * because the attestation is a rule of the domain and not of HTTP.
 */
class AdminController
{
    use JsonResponder;

    /**
     * What an admin may correct on somebody else's submission.
     *
     * The same rule set as the public form's, minus `required` — a PATCH
     * carries only the fields being changed — and it has to stay the same set:
     * an edit that could write a value the public endpoint would have refused
     * turns the review screen into a way around validation.
     *
     * Absent, on purpose: `mandate_reference` (printed on the paper the member
     * signed — ADR-0006, and changing it silently invalidates the signature),
     * `privacy_notice_url` and `privacy_notice_shown_at` (the club's evidence
     * of what was shown, and evidence nobody may edit), and the two timestamps
     * that are the retention rule.
     */
    private const FIELD_RULES = [
        'first_name' => ['nullable', 'string', 'max:100'],
        'last_name' => ['nullable', 'string', 'max:100'],
        'email' => ['nullable', 'email', 'max:255'],
        'date_of_birth' => ['nullable', 'date', 'past_date'],
        'preferred_language' => ['nullable', 'string'],
        'account_holder_name' => ['nullable', 'string', 'max:70'],
        'iban' => ['nullable', 'string', 'iban', 'max:34'],
    ];

    /**
     * The fields where a blank means "no value" rather than the empty string.
     *
     * The two optional ones only. A cleared `first_name` is not a request to
     * store nothing — the column is NOT NULL — so it stays a validation error
     * rather than becoming a 500 from MariaDB.
     */
    private const BLANK_MEANS_NULL = ['account_holder_name'];

    public function __construct(
        private RegistrationReviewService $registrations,
        private RegistrationLinkService $links,
        private Validator $validator,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $query = ListQuery::fromParams($request->getQueryParams(), defaultSortKey: 'submitted_at');

        $result = $this->registrations->list(
            $query->perPage,
            $query->offset,
            $query->sortKey,
            $query->sortOrder,
            $query->search,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, $this->registrations->get($args['registrationId'])->toArray());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $body = self::withBlanksAsNull($request->getParsedBody() ?? []);

        // Only the fields the request carries are checked, so a PATCH of one
        // field is not rejected for the absence of the others.
        $rules = array_intersect_key(self::FIELD_RULES, $body);
        if (isset($rules['preferred_language'])) {
            $rules['preferred_language'][] = 'in:' . implode(',', array_column(SupportedLanguage::cases(), 'value'));
        }

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        // Narrowed to what this surface may write, and narrowed *here* rather
        // than only in the service, so a form that echoes the whole row back —
        // the reference printed on the signed paper, the notice URL, the
        // expiry — is not refused for sending fields it never edited. They are
        // simply not an admin's to change, so they are dropped.
        $registration = $this->registrations->update(
            $args['registrationId'],
            array_intersect_key($body, self::FIELD_RULES),
            $request->getAttribute('admin_user_id'),
        );

        return $this->json($response, $registration->toArray());
    }

    /**
     * POST …/approve — the attestation, and the only door to a member row.
     *
     * `signed_mandate_confirmed` is required and must be exactly true. It is
     * not a checkbox the frontend can default: it is an admin stating they hold
     * the signed SEPA mandate, and — where the club printed the form with the
     * IBAN comb left blank for a hand-written number — that the number written
     * on it matches the `****last4` on file.
     *
     * `boolean` rather than `accepted`, and then compared here: a false sent
     * explicitly is a *stated* refusal to attest, and the service answers it
     * with a translatable reason rather than with a field-level validation
     * error about a checkbox.
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];

        $valid = $this->validator->validate($body, [
            'mandate_signed_at' => ['required', 'date', 'past_date'],
            'signed_mandate_confirmed' => ['required', 'boolean'],
        ]);

        if (!$valid) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $member = $this->registrations->approve(
            $args['registrationId'],
            (string) $body['mandate_signed_at'],
            filter_var($body['signed_mandate_confirmed'], FILTER_VALIDATE_BOOLEAN),
            $request->getAttribute('admin_user_id'),
        );

        return $this->json($response, $member->toArray(), 201);
    }

    /**
     * GET …/document — the club's Anmeldung, filled, for the Kassenwart to print.
     *
     * Streamed as the PDF itself rather than wrapped in JSON: this is a file a
     * person prints, and the browser is entitled to open it directly. `inline`
     * rather than `attachment` for the same reason — the admin wants a print
     * dialog, not a download folder.
     *
     * `no-store` matters here as much as it does on the member's copy. The
     * sheet carries a name, a birth date, an email and an IBAN hint, and a
     * cached copy of it on a shared clubhouse machine is exactly the leak the
     * masking everywhere else in this module exists to prevent.
     */
    public function document(Request $request, Response $response, array $args): Response
    {
        $pdf = $this->registrations->renderForPrint(
            $args['registrationId'],
            $request->getAttribute('admin_user_id'),
        );

        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="anmeldung.pdf"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * POST …/reject — delete the submission.
     *
     * The reason is optional and travels only into the audit entry, which is
     * the sole thing that survives: the row it describes is gone before the
     * response is written.
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $body = self::withBlanksAsNull($request->getParsedBody() ?? []);

        if (!$this->validator->validate($body, ['reason' => ['nullable', 'string', 'max:500']])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $this->registrations->reject(
            $args['registrationId'],
            $body['reason'] ?? null,
            $request->getAttribute('admin_user_id'),
        );

        return $response->withStatus(204);
    }

    /**
     * POST …/link — mail the club's registration link to a prospective member
     * (#821, UC-A70).
     *
     * The one **outbound** verb on an inbox, and the only one here that names
     * nobody in this database: the address is typed by an admin and belongs to
     * somebody who has no row anywhere, and none is created (ADR-0053).
     *
     * `email` is validated as an address and nothing more. There is no
     * membership check, no duplicate check and no verification step — the link
     * carries no credential, so a misdirected one produces at worst a pending
     * registration that fails review, and the trust boundary is the signed paper
     * that comes later.
     *
     * 202 rather than 200: nothing has been delivered when this returns, only
     * queued. The drain sends on its next tick, and a client that reads 200 as
     * "it has arrived" would be wrong in a way the admin then repeats to the
     * person waiting for it.
     */
    public function sendLink(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, ['email' => ['required', 'email', 'max:255']])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $this->links->send((string) $body['email'], $request->getAttribute('admin_user_id'));

        return $response->withStatus(202);
    }

    /**
     * A cleared optional field arrives as `""`; the two that are nullable in
     * the column mean "no value" by it.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function withBlanksAsNull(array $body): array
    {
        foreach (self::BLANK_MEANS_NULL as $field) {
            if (array_key_exists($field, $body) && $body[$field] === '') {
                $body[$field] = null;
            }
        }

        return $body;
    }
}
