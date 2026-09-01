<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Why a business rule refused — as a code a client can translate.
 *
 * A `BusinessRuleException`'s message is written in English, for the log and
 * for an API consumer reading a raw response. The admin panel is neither: it
 * renders in the admin's own language (German by default), and until #757 it
 * showed whatever prose the backend happened to send. An admin who had never
 * set the panel to English was told "Cannot anonymize: outstanding balance of
 * €7.50" — the one sentence on the screen that was not in their language.
 *
 * The fix is that the *reason* travels, not the sentence. Every refusal names
 * a case of this enum; `ErrorHandler` puts it in the response as `reason`,
 * with `params` for the values the sentence needs, and the frontend looks up
 * `errors.reasons.<value>` in its locale file and interpolates. The English
 * message stays exactly as it was, so logs and API tests are unaffected.
 *
 * **Rules**
 *
 * 1. A case is added here *with* its `errors.reasons.<value>` entry in both
 *    `admin-frontend/public/locales/{de,en}.json`. `ReasonTranslationTest`
 *    fails when one is missing, so a new refusal cannot ship untranslated.
 * 2. Params are values, never prose: cents, ids, counts, ISO dates. A param
 *    the frontend has to show as a sentence has to be a reason of its own —
 *    an English fragment interpolated into a German sentence is the same bug
 *    in a smaller box.
 * 3. Money is passed as integer cents under a `*_cents` key. The frontend
 *    formats it with the admin's locale, so the same amount reads `7,50 €`
 *    in German and `€7.50` in English. Never pre-format an amount here.
 * 4. A value is part of the API contract once released — rename nothing;
 *    add a case and retire the old one.
 */
enum BusinessRuleReason: string
{
    // ── Members ────────────────────────────────────────────────────────────
    case MEMBER_ALREADY_ANONYMIZED = 'member_already_anonymized';
    case MEMBER_BALANCE_OUTSTANDING = 'member_balance_outstanding';
    case MEMBER_IN_ACTIVE_SETTLEMENT = 'member_in_active_settlement';
    case MEMBER_ANONYMIZATION_FAILED = 'member_anonymization_failed';

    // ── Products ───────────────────────────────────────────────────────────
    case CATEGORY_INACTIVE = 'category_inactive';

    // ── Admin users ────────────────────────────────────────────────────────
    case ADMIN_USER_NEEDS_A_ROLE = 'admin_user_needs_a_role';
    case ADMIN_ROLE_IS_EXCLUSIVE = 'admin_role_is_exclusive';
    case LAST_ADMIN_ROLE_HOLDER = 'last_admin_role_holder';
    case CANNOT_DEACTIVATE_SELF = 'cannot_deactivate_self';
    case LAST_ACTIVE_ADMIN = 'last_active_admin';
    /**
     * A presented invitation link is not usable — unknown, expired, already
     * accepted, or replaced by a newer one.
     *
     * **One reason for four causes, deliberately.** The endpoint that answers
     * this carries no session: telling an anonymous caller that a token is
     * "expired" rather than "unknown" confirms the token existed, which turns
     * the accept surface into an oracle for guessing them. The log records
     * which of the four it actually was; the wire says only that the link does
     * not work and to ask for a new one.
     */
    case INVITATION_INVALID = 'invitation_invalid';
    /**
     * A credential was asked for on behalf of a deactivated account.
     *
     * Without this, deactivating a colleague would not stop an outstanding
     * invitation from being renewed — the account cannot sign in, so the link
     * grants nothing, but issuing one says otherwise to whoever receives it.
     */
    case ADMIN_ACCOUNT_INACTIVE = 'admin_account_inactive';
    /**
     * A replacement invitation was asked for on an account that has already
     * accepted one.
     *
     * The invitation is an onboarding credential, not a password-reset
     * channel: an emailed link that can re-credential an established admin is
     * a second way past the step-up guarding
     * `POST /admin-users/{id}/reset-password`, and it would be reachable by
     * anyone who reads that admin's mailbox.
     */
    case ADMIN_ALREADY_ONBOARDED = 'admin_already_onboarded';

    // ── Settlement lifecycle ───────────────────────────────────────────────
    case SETTLEMENT_ALREADY_CANCELLED = 'settlement_already_cancelled';
    case SETTLEMENT_BANK_TRANSFER_NOT_CANCELLABLE = 'settlement_bank_transfer_not_cancellable';
    case SETTLEMENT_SUBMITTED_NOT_CANCELLABLE = 'settlement_submitted_not_cancellable';
    case SETTLEMENT_EXECUTION_DATE_PASSED = 'settlement_execution_date_passed';
    case SETTLEMENT_NOT_YET_COLLECTED = 'settlement_not_yet_collected';
    case SETTLEMENT_CANCELLED_NOT_SUBMITTABLE = 'settlement_cancelled_not_submittable';
    case SETTLEMENT_ALREADY_SUBMITTED = 'settlement_already_submitted';
    case SETTLEMENT_METHOD_NOT_SUBMITTABLE = 'settlement_method_not_submittable';
    case SETTLEMENT_NOT_EXPORTED_YET = 'settlement_not_exported_yet';

    // ── Settlement creation ────────────────────────────────────────────────
    case NO_MEMBERS_NAMED = 'no_members_named';
    case TRANSACTIONS_ALREADY_SETTLED = 'transactions_already_settled';
    case NO_UNSETTLED_TRANSACTIONS = 'no_unsettled_transactions';
    case NO_UNSETTLED_TRANSACTIONS_FOR_FILTERS = 'no_unsettled_transactions_for_filters';
    case NO_COLLECTABLE_MEMBERS_FOR_FILTERS = 'no_collectable_members_for_filters';
    case SETTLEMENT_TOTAL_IS_ZERO = 'settlement_total_is_zero';

    // ── Settlement reversal ────────────────────────────────────────────────
    case SETTLEMENT_CANCELLED_NOT_REVERSIBLE = 'settlement_cancelled_not_reversible';
    case SETTLEMENT_HAS_NO_MEMBERS_TO_REVERSE = 'settlement_has_no_members_to_reverse';
    case MEMBERS_ALREADY_REVERSED = 'members_already_reversed';
    case MEMBER_NOT_ON_COLLECTION_HOLD = 'member_not_on_collection_hold';

    // ── SEPA export ────────────────────────────────────────────────────────
    case SETTLEMENT_CANCELLED_NOT_EXPORTABLE = 'settlement_cancelled_not_exportable';
    case SETTLEMENT_HAS_REVERSALS = 'settlement_has_reversals';
    case SETTLEMENT_METHOD_NOT_EXPORTABLE = 'settlement_method_not_exportable';
    case SEPA_CONFIG_INCOMPLETE = 'sepa_config_incomplete';
    case EXECUTION_DATE_NOT_BUSINESS_DAY = 'execution_date_not_business_day';
    case SETTLEMENT_HAS_NO_ITEMS = 'settlement_has_no_items';
    case SEPA_EXPORT_NOTHING_OWED = 'sepa_export_nothing_owed';
    case SEPA_EXPORT_EVERY_MEMBER_EXCLUDED = 'sepa_export_every_member_excluded';
    case MANDATE_VANISHED_DURING_EXPORT = 'mandate_vanished_during_export';
    case IBAN_KEY_UNAVAILABLE = 'iban_key_unavailable';
    case SEPA_XML_MALFORMED = 'sepa_xml_malformed';

    // Self-registration (ADR-0052). `REGISTRATION_DISABLED` is the only one of
    // these an anonymous caller ever sees, and it is deliberately talkative:
    // the person holding the poster is standing in the clubhouse, and a blank
    // refusal there reads as a broken feature rather than as a club decision.
    // A wrong or missing poster secret is *not* here — it answers a uniform
    // 404, because telling a prober that a valid secret exists is the one
    // disclosure the gate is for.
    case REGISTRATION_DISABLED = 'registration_disabled';
    case DOCUMENT_URL_MISSING = 'document_url_missing';

    // Review refusals (#779). These an admin sees, in the panel, in their own
    // language — so each needs its `errors.reasons.<code>` entry in both locale
    // files, which `reasons.test.ts` enforces.
    /**
     * Approval without the attestation.
     *
     * The confirmation flag is the whole point of the approve endpoint: it is
     * an admin stating they have the signed mandate in hand and that its IBAN
     * matches the `****last4` on file. Defaulting it to true, or letting it be
     * absent, would turn a legal attestation into a button.
     */
    case REGISTRATION_ATTESTATION_REQUIRED = 'registration_attestation_required';
    /**
     * The club already has a member at this address.
     *
     * `members.email` carries no UNIQUE constraint, so nothing in the database
     * would have refused this — approval would quietly create a second member
     * for one person, and the club would find out at the next settlement, when
     * both got a statement. Refused here instead, with the duplicate flag on
     * the review screen having warned first.
     */
    case REGISTRATION_MEMBER_EMAIL_EXISTS = 'registration_member_email_exists';
}
