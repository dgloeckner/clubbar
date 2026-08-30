<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Enums;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailSubject;
use App\Modules\Notifications\Services\MailRetention;
use App\Shared\Enums\EntityType;
use PHPUnit\Framework\TestCase;

/**
 * What a kind decides about its own row (ADR-0038, ADR-0039, #462 step 11.5).
 *
 * `mail_outbox` deliberately has no column for any of this: what `subject_id`
 * points at, who the message is addressed to, and how long a delivered copy is
 * kept are all properties of the kind, and storing them would create a second
 * place for each to be wrong. That makes this enum the single source, and these
 * tests the place it is held to it.
 */
class MailKindTest extends TestCase
{
    /**
     * The invitation is admin-addressed, about an admin account, and reaches
     * exactly one address — never a fan-out and never the club.
     *
     * The club copy is the one worth stating: `admin_account_created` fires
     * from the same request and already tells the club that an account was
     * given out. A second club copy carrying a **working link into that
     * account** would put a live credential on a list.
     */
    public function test_an_invitation_is_addressed_to_the_invitee_alone(): void
    {
        $this->assertSame(MailSubject::ADMIN_USER, MailKind::ADMIN_INVITATION->subjectType());
        $this->assertFalse(MailKind::ADMIN_INVITATION->addressesMember());
        $this->assertFalse(MailKind::ADMIN_INVITATION->addressesClub());
        $this->assertSame([AdminRole::ADMIN], MailKind::ADMIN_INVITATION->recipientRoles());
    }

    public function test_a_statement_is_about_the_member_it_is_addressed_to(): void
    {
        $this->assertSame(MailSubject::MEMBER, MailKind::DECKEL_STATEMENT->subjectType());
        $this->assertTrue(MailKind::DECKEL_STATEMENT->addressesMember());
    }

    /**
     * The regression `addressesMember()` was rewritten to prevent.
     *
     * It read `subjectType() === MailSubject::SETTLEMENT`, which was true of
     * every kind that existed at the time and would have returned **false** for
     * the first member-addressed kind that was about something other than a
     * settlement. A statement would then have been treated as operational mail
     * to an admin — and nothing would have said so.
     */
    public function test_every_kind_states_its_own_audience_rather_than_inheriting_one(): void
    {
        $memberFacing = [
            MailKind::SEPA_PRENOTIFICATION,
            MailKind::CANCELLATION_NOTICE,
            MailKind::DECKEL_STATEMENT,
            // ADR-0051. The first member-addressed kinds that are not about
            // money at all — an onboarding, a card, an address — which is the
            // second time the shape of the existing kinds would have given the
            // wrong answer to a new one.
            MailKind::MEMBER_WELCOME,
            MailKind::MEMBER_CARD_REPLACED,
            MailKind::MEMBER_EMAIL_CHANGED,
            MailKind::MEMBER_EMAIL_ACTIVATED,
        ];

        foreach (MailKind::cases() as $kind) {
            $this->assertSame(
                in_array($kind, $memberFacing, true),
                $kind->addressesMember(),
                $kind->value . ' must say who it is addressed to'
            );
        }
    }

    /** A statement is audited against the member, so the audit log's filter can find it. */
    public function test_a_statement_is_filed_under_the_member(): void
    {
        $this->assertSame(EntityType::MEMBER, MailSubject::MEMBER->auditEntityType());
    }

    // ------------------------------------------------------------------
    // Jugendschutz violation (#622, ADR-0045 §3)
    //
    // The first kind whose subject is a **transaction**. Every kind before it
    // was about a settlement, a member, a terminal, a key or an admin account —
    // a violation is about one sale, and the sale is the only thing that
    // identifies it.
    // ------------------------------------------------------------------

    public function test_a_violation_notice_is_about_the_transaction_it_names(): void
    {
        $this->assertSame(MailSubject::TRANSACTION, MailKind::JUGENDSCHUTZ_VIOLATION->subjectType());
    }

    public function test_a_violation_notice_is_filed_under_the_transaction(): void
    {
        // Filed under the sale rather than the member, which is what keeps the
        // anonymization scrub — it keys on the member's own entity_id — from
        // reaching inside it (ADR-0013 principle 8).
        $this->assertSame(EntityType::TRANSACTION, MailSubject::TRANSACTION->auditEntityType());
    }

    /**
     * Never to the member.
     *
     * The member in a Jugendschutz violation is the person it is *about*, and
     * telling them the club has recorded an incident against them is neither
     * the point nor safe: the mail reaches whoever runs the bar, so it must be
     * addressed to them.
     */
    public function test_a_violation_notice_is_never_addressed_to_the_member(): void
    {
        $this->assertFalse(MailKind::JUGENDSCHUTZ_VIOLATION->addressesMember());
    }

    /**
     * The club address gets a copy, unlike every other operational warning.
     *
     * `addressesClub()` is otherwise reserved for admin *lifecycle* events, on
     * the reasoning that a Vorstand list has nothing to do about an expiring
     * token and routing every warning there turns the one channel that must be
     * read into one that is filtered.
     *
     * A youth-protection incident is the exception that reasoning allows for:
     * JuSchG § 28 exposure lands on the club and on whoever served, so the
     * Vorstand is not a bystander here — and unlike a token warning, this
     * should be rare enough that it never becomes noise. If it is not rare,
     * that is a bigger problem than an over-full inbox.
     */
    public function test_a_violation_notice_also_reaches_the_club_address(): void
    {
        $this->assertTrue(MailKind::JUGENDSCHUTZ_VIOLATION->addressesClub());
    }

    /**
     * The existing kinds keep the audience they shipped with.
     *
     * Turning a `||` into a `match` is exactly the kind of edit that quietly
     * moves one arm, and the two settlement kinds are the ones a member
     * actually receives.
     */
    public function test_the_settlement_kinds_are_unchanged(): void
    {
        $this->assertSame(MailSubject::SETTLEMENT, MailKind::SEPA_PRENOTIFICATION->subjectType());
        $this->assertSame(MailSubject::SETTLEMENT, MailKind::CANCELLATION_NOTICE->subjectType());
        $this->assertFalse(MailKind::KEY_EXPIRY_WARNING->addressesMember());
        $this->assertFalse(MailKind::TERMINAL_ANOMALY_WARNING->addressesMember());
    }

    /** ADR-0039 decision 6: retention is the fourth thing a kind decides. */
    public function test_a_delivered_statement_is_pruned_on_its_own_schedule(): void
    {
        $this->assertSame(
            MailRetention::STATEMENT_SENT_DAYS,
            MailRetention::sentDaysFor(MailKind::DECKEL_STATEMENT)
        );
    }

    /* ─────────────────── Which office a kind is for (#633) ─────────────────── */

    /**
     * Every kind states its offices, and the answer holds for kinds that do not
     * exist yet: a `match` with no default means a new case fails to compile
     * rather than quietly inheriting somebody else's audience. This is the same
     * guard `addressesMember()` gets above, for the same reason — the failure it
     * prevents is silent and is a message read by the wrong office.
     */
    public function test_every_admin_addressed_kind_names_the_offices_it_is_for(): void
    {
        foreach (MailKind::cases() as $kind) {
            $roles = $kind->recipientRoles();

            if ($kind->addressesMember()) {
                $this->assertSame([], $roles, $kind->value . ' is not fanned out to any office');
                continue;
            }

            $this->assertNotEmpty($roles, $kind->value . ' must name at least one office');
            $this->assertContains(
                AdminRole::ADMIN,
                $roles,
                $kind->value . ' — `admin` is the root of the ladder and receives whatever a lesser office does'
            );
        }
    }

    /**
     * The rule the mapping follows: mirror the grant on the surface the mail
     * points at. Keys, terminals and admin accounts are `admin`-only routes
     * (ADR-0044), so their mail is `admin`-only — including for the Kassenwart,
     * to whom a key fingerprint is as foreign as it is to the stock keeper.
     */
    public function test_operational_credential_mail_is_admin_only(): void
    {
        $adminOnly = [
            MailKind::KEY_EXPIRY_WARNING,
            MailKind::ENCRYPTION_KEY_REGISTERED,
            MailKind::ENCRYPTION_KEY_ACTIVATED,
            MailKind::ENCRYPTION_KEY_REVOKED,
            MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
            MailKind::TERMINAL_ANOMALY_WARNING,
            MailKind::TERMINAL_TOKEN_ISSUED,
            MailKind::ADMIN_EMAIL_CHANGED,
            MailKind::ADMIN_ACCOUNT_CREATED,
            MailKind::ADMIN_ROLE_CHANGED,
        ];

        foreach ($adminOnly as $kind) {
            $this->assertSame([AdminRole::ADMIN], $kind->recipientRoles(), $kind->value);
        }
    }

    /**
     * The one kind whose surface is not `admin`-only. Its dashboard alert is
     * `TREASURY` because ADR-0045 names the Kassenwart as the recipient, and
     * the mail carries exactly that set — one rule, not two tables.
     */
    public function test_a_violation_notice_reaches_the_office_its_dashboard_alert_does(): void
    {
        $this->assertSame(
            [AdminRole::ADMIN, AdminRole::KASSENWART],
            MailKind::JUGENDSCHUTZ_VIOLATION->recipientRoles(),
        );
    }

    /**
     * The account holding only the bar stock is on no operational fan-out at
     * all — the thing #633 is about, stated once over every kind rather than
     * per kind.
     */
    public function test_the_getraenkewart_is_on_no_admin_fan_out(): void
    {
        foreach (MailKind::cases() as $kind) {
            $this->assertNotContains(
                AdminRole::GETRAENKEWART,
                $kind->recipientRoles(),
                $kind->value . ' must not reach an office that cannot open the screen it is about'
            );
        }
    }
}
