<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Enums;

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
}
