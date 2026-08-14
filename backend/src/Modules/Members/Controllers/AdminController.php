<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Shared\Validation\Validator;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    /**
     * One rule set for the member fields a client may write.
     *
     * Create and update used to disagree about which fields were checked at
     * all: `phone`, `email`, `mandate_reference` and `mandate_signed_at`
     * carried no length or format rule on either path, so an over-long phone
     * number or a malformed date reached MariaDB in strict mode and came back
     * as a PDOException — a 500 that named nothing (#117). The lengths mirror
     * the column widths in `001_initial_schema.sql` and `007_critical_remediation.sql`.
     *
     * Create adds `required` (and the `unique` card check) on top; update
     * validates only the keys the request actually carries, so a PATCH of one
     * field is not rejected for the absence of the others.
     */
    private const FIELD_RULES = [
        'first_name' => ['nullable', 'string', 'max:100'],
        'last_name' => ['nullable', 'string', 'max:100'],
        'email' => ['nullable', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:20'],
        'preferred_language' => ['nullable', 'string', 'in:de,en,fr'],
        'account_holder_name' => ['nullable', 'string', 'max:70'],
        'card_uid' => ['nullable', 'string', 'min:8', 'max:20', 'regex:/^[0-9A-F]+$/'],
        // `iban` bounds the compact form at 34 characters on its own; `max:34`
        // additionally rejects a value padded past the column width with the
        // spaces IBANs are conventionally printed with.
        'iban' => ['nullable', 'string', 'iban', 'max:34'],
        'mandate_reference' => ['nullable', 'string', 'max:35'],
        'mandate_signed_at' => ['nullable', 'date'],
    ];

    /**
     * The optional fields where a blank string means "no value".
     *
     * A form has no way to send "absent": a field the volunteer cleared arrives
     * as `""`. Create normalized three of these inline and update normalized
     * none, so the same cleared field meant different things depending on which
     * button produced the request (#111). `phone` and `account_holder_name`
     * were stored as the empty string on update; `card_uid` could not be
     * cleared at all, since a blank fell foul of its own `min:8` rule and came
     * back as "must be at least 8 characters" for a card that had simply been
     * handed back. That length rule is also the only thing standing between an
     * empty `card_uid` and the UNIQUE index it shares — UNIQUE permits many
     * NULLs but only one empty string, so the second member cleared this way
     * would collide. Both paths now run the same list, so the two cannot drift
     * apart again.
     *
     * `mandate_reference` is deliberately absent. Blank does not mean absent on
     * the create path: an absent reference is minted from the member id
     * (ADR-0006), while an explicitly blank one says the member has no mandate
     * and must stay without one (#164) — a distinction the repository reads
     * from key presence and which mapping to null would erase. It needs no
     * normalization here in any case: `MembersRepository` resolves the value
     * with `?:` on both paths before it can reach the UNIQUE column.
     */
    private const BLANK_MEANS_NULL = [
        'phone',
        'card_uid',
        'iban',
        'account_holder_name',
        'mandate_signed_at',
    ];

    /**
     * Map the cleared fields of a request body to null before anything reads it.
     *
     * This runs ahead of validation on purpose: `card_uid: ""` would otherwise
     * be rejected by `min:8`, so a volunteer removing a lost member card could
     * not save the form at all.
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

    /**
     * On update, a blank IBAN field means "keep the stored account".
     *
     * The edit form cannot prefill the IBAN any more — it is sealed and the API
     * returns only the last four characters (ADR-0036) — so the field arrives
     * blank on every save that did not deliberately retype it. Under
     * `BLANK_MEANS_NULL` that blank read as "clear it", which would revoke the
     * mandate of every member whose phone number an admin corrected (#392).
     *
     * Dropping the key entirely is what `applyMandateChange` already reads as
     * "the caller said nothing about banking data". Revoking a mandate stays
     * possible and stays explicit: a JSON `null` still means clear, and it
     * survives this because it is not the empty string.
     *
     * Create is deliberately untouched. There is nothing stored to keep, so a
     * blank there means the member has no mandate — the existing meaning.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function withBlankIbanAsAbsent(array $body): array
    {
        if (array_key_exists('iban', $body) && $body['iban'] === '') {
            unset($body['iban']);
        }

        return $body;
    }

    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
        private SettlementsService $settlementsService,
        private CollectionHoldService $collectionHoldService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params);

        // Support both filters[is_active] (nested) and is_active (direct) formats
        $filters = [];
        if (isset($params['filters']['is_active'])) {
            // Convert string "true"/"false" to boolean
            $filters['is_active'] = filter_var($params['filters']['is_active'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($params['is_active'])) {
            // Convert string "true"/"false" to boolean
            $filters['is_active'] = filter_var($params['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        // OAS: status=active|inactive (top-level param)
        $statusParam = $params['status'] ?? null;
        if ($statusParam === 'active') {
            $filters['is_active'] = true;
        } elseif ($statusParam === 'inactive') {
            $filters['is_active'] = false;
        }

        // Language filter
        if (isset($params['filters']['language'])) {
            $filters['language'] = $params['filters']['language'];
        } elseif (isset($params['language'])) {
            $filters['language'] = $params['language'];
        }

        // Card UID filter — OAS: has_card_uid=with|without (top-level param)
        $cardUidParam = $params['has_card_uid'] ?? $params['filters']['has_card_uid'] ?? null;
        if ($cardUidParam === 'with' || $cardUidParam === 'true') {
            $filters['has_card_uid'] = true;
        } elseif ($cardUidParam === 'without' || $cardUidParam === 'false') {
            $filters['has_card_uid'] = false;
        }

        // SEPA status filter — OAS: sepa_status=valid|invalid (top-level param)
        $sepaParam = $params['sepa_status'] ?? $params['filters']['sepa_status'] ?? null;
        if ($sepaParam !== null && in_array($sepaParam, ['valid', 'invalid', 'missing'], true)) {
            $filters['sepa_status'] = $sepaParam;
        }

        $result = $this->membersService->listMembers(
            $query->perPage,
            $query->offset,
            $filters,
            $query->sortKey,
            $query->sortOrder,
            $query->search,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function store(Request $request, Response $response): Response
    {
        $body = self::withBlanksAsNull($request->getParsedBody() ?? []);
        $adminId = $request->getAttribute('admin_user_id');

        $rules = self::FIELD_RULES;
        $rules['first_name'][] = 'required';
        $rules['last_name'][] = 'required';
        $rules['email'][] = 'required';
        $rules['preferred_language'][] = 'required';
        $rules['card_uid'][] = 'unique:members,card_uid';

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $language = SupportedLanguage::from($body['preferred_language']);

        $member = $this->membersService->createMember(
            firstName: $body['first_name'],
            lastName: $body['last_name'],
            email: $body['email'],
            phone: $body['phone'] ?? null,
            cardUid: $body['card_uid'] ?? null,
            language: $language,
            iban: $body['iban'] ?? null,
            accountHolderName: $body['account_holder_name'] ?? null,
            // Passed through as sent — see BLANK_MEANS_NULL for why a blank
            // reference is not the same as an absent one on this path.
            mandateReference: $body['mandate_reference'] ?? null,
            mandateSignedAt: $body['mandate_signed_at'] ?? null,
            adminUserId: $adminId,
        );

        return $this->json($response, $member->toArray(), 201);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $member   = $this->membersService->getMember($memberId);

        return $this->json($response, $member->toArray());
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $body = self::withBlanksAsNull(self::withBlankIbanAsAbsent($request->getParsedBody() ?? []));
        $adminId = $request->getAttribute('admin_user_id');

        // Only the fields the request carries are checked — three of them used
        // to be checked one at a time in separate passes, and the other seven
        // not at all.
        $rules = array_intersect_key(self::FIELD_RULES, $body);
        if (isset($rules['card_uid'])) {
            $rules['card_uid'][] = "unique:members,card_uid,{$memberId}";
        }

        if ($rules !== [] && !$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $member = $this->membersService->updateMember($memberId, $body, $adminId);

        return $this->json($response, $member->toArray());
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->membersService->deleteMember($memberId, $adminId);

        return $this->json($response, ['message' => 'Member deleted'], 200);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $exportData = $this->membersService->exportMember($memberId);

        return $this->json($response, $exportData);
    }

    public function anonymize(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $adminId  = $request->getAttribute('admin_user_id');

        // GDPR: anonymizeMember() scrubs the member's PII and audit history
        // only after its eligibility checks have passed (#85), so a refused
        // attempt leaves the member record untouched.
        $member = $this->membersService->anonymizeMember($memberId, $adminId);

        return $this->json($response, $member->toArray());
    }

    /**
     * Standing "credit balances outstanding" listing under Members (#161
     * work item 3) — every member the club currently owes money.
     */
    public function creditBalances(Request $request, Response $response): Response
    {
        $result = $this->settlementsService->listCreditBalances();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_credit_cents' => $result['total_credit_cents'],
        ]);
    }

    /**
     * Standing "no usable mandate" listing under Members (#258) — the members
     * the next run cannot collect from at all.
     *
     * The worst of the three exclusions and, until this endpoint, the least
     * visible: a member in credit or on hold is at least an account somebody
     * is transacting with, while a member with no mandate can never be
     * collected from and was only discoverable by reading the SEPA column of
     * the members list row by row.
     */
    public function mandateMissing(Request $request, Response $response): Response
    {
        $result = $this->settlementsService->listMembersWithoutMandate();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_uncollectable_cents' => $result['total_uncollectable_cents'],
        ]);
    }

    /**
     * Standing "on collection hold" listing under Members (ruling #148 §4) —
     * every member the next run will skip, and why.
     *
     * It sits beside credit balances for the same reason that one does: an
     * exclusion nobody can see is an exclusion nobody ever resolves.
     */
    public function collectionHolds(Request $request, Response $response): Response
    {
        $result = $this->collectionHoldService->listHeld();

        return $this->json($response, [
            'items' => array_map(fn($item) => $item->toArray(), $result['items']),
            'total_held_cents' => $result['total_held_cents'],
        ]);
    }

    /** Let the next run collect from this member again (ruling #148 §5). */
    public function clearCollectionHold(Request $request, Response $response, array $args): Response
    {
        $adminId = $request->getAttribute('admin_user_id');
        $hold = $this->collectionHoldService->clearHold($args['memberId'], $adminId);

        return $this->json($response, $hold->toArray());
    }

}
