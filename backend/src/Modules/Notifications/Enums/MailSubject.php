<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Shared\Enums\EntityType;

/**
 * What kind of thing a queued message is *about* (ADR-0038).
 *
 * `mail_outbox.subject_id` is polymorphic — a settlement id, an encryption key
 * id, a terminal id — and this enum is what says which. Deliberately **not** a
 * column: the subject type is a property of the `MailKind`, not an independent
 * fact about the row, so storing it would create a second place for it to be
 * wrong. {@see MailKind::subjectType()} is the only source.
 *
 * The consequence is that `subject_id` carries no foreign key, because a
 * polymorphic one cannot exist. That is a real loss and worth stating plainly:
 * nothing at the database level stops a row pointing at a settlement that was
 * deleted. It costs little here because the application never deletes a
 * settlement — ADR-0032 makes it append-only, and cancellation marks rather
 * than removes — and a builder handed an orphan refuses to invent a message
 * around the gap rather than sending a wrong one.
 */
enum MailSubject: string
{
    case SETTLEMENT = 'settlement';
    case ENCRYPTION_KEY = 'encryption_key';
    case TERMINAL = 'terminal';

    /**
     * An admin account. Used by the security notices that tell somebody
     * something changed about their own login — where the subject and the
     * recipient are the same account, and the address written to is one the
     * account no longer has.
     */
    case ADMIN_USER = 'admin_user';

    /**
     * The member themselves (ADR-0039).
     *
     * Like {@see ADMIN_USER} above, and unlike the three before it, this names
     * the person rather than a thing that happened to them: a Deckelauszug is
     * about the member and nothing else — no settlement, no run, no entity to
     * point at, and ADR-0039 rejected inventing one. So `subject_id` is the
     * member id, and unlike the polymorphic cases this one does have a real
     * column beside it: the row's `member_id` carries the same value, because
     * that is the column erasure and the delete cascade key on.
     */
    case MEMBER = 'member';

    /**
     * What the audit entry for this message hangs off (ADR-0013).
     *
     * An announcement is an event in a settlement's life; a key expiry warning
     * is an event in a key's. Filing both under `settlement` would make the
     * audit log's entity filter — the way anybody actually reads it — lie about
     * the second.
     */
    /**
     * One sale (#622, ADR-0045 §3).
     *
     * The first subject that is an *event* rather than a party or a piece of
     * infrastructure. A Jugendschutz violation is about a transaction and
     * nothing else: the member is who it happened to, the product is what was
     * handed over, but the thing that occurred — and the thing the audit entry
     * is filed under — is the sale.
     *
     * Filing it here rather than under {@see MEMBER} is deliberate and is what
     * keeps the erasure scrub out of it. That scrub keys on the member's own
     * `entity_id`, so a violation filed under the member would be reachable by
     * it, and an erased member would either take the record of the incident
     * with them or leave a scrubbed hole where it used to be. Under the
     * transaction it is simply out of reach — which is the same reason M7 filed
     * the audit entry there.
     */
    case TRANSACTION = 'transaction';

    /**
     * The club's credit-limit policy — the singleton `credit_limit_config` row
     * (ADR-0047), whose `subject_id` is therefore the literal `1`.
     *
     * The first subject that is a **setting** rather than a party, a piece of
     * infrastructure or an event. The near-limit digest is about the club's
     * ceiling and who is up against it; it names no one member because it is
     * about all of them at once, and inventing a per-member subject would turn
     * one aggregate mail into a fan-out of the very thing it exists to replace.
     *
     * Filing it here also keeps the erasure scrub out of it, the same way
     * {@see TRANSACTION} does: that scrub keys on a member's own `entity_id`,
     * and a digest filed under the configuration is simply out of its reach —
     * which is right, because the row records that a *report* was queued, not
     * anything about the members who happened to be in it. The message body is
     * rebuilt from live data at send time and carries no member data in the
     * queue at all.
     */
    case CREDIT_LIMIT_CONFIG = 'credit_limit_config';

    /**
     * This installation itself — `subject_id` is the literal `1`.
     *
     * Used by the backup client-secret warning (#691), which is about a value
     * in `config.php` and about nothing else. There is deliberately no backup
     * entity to name: ADR-0049 decision 8 keeps the backup out of the database
     * it dumps entirely — no table, no migration, no audit vocabulary — and
     * adding one here to give a mail a subject would undo that for the sake of
     * a foreign key `subject_id` does not have anyway.
     *
     * Like {@see CREDIT_LIMIT_CONFIG}, filing here also keeps the erasure
     * scrub out of it: that scrub keys on a member's own `entity_id`, and a
     * warning about a credential has nothing to do with any member.
     */
    case INSTANCE_CONFIG = 'instance_config';

    public function auditEntityType(): EntityType
    {
        return match ($this) {
            self::SETTLEMENT => EntityType::SETTLEMENT,
            self::ENCRYPTION_KEY => EntityType::ENCRYPTION_KEY,
            self::TERMINAL => EntityType::TERMINAL,
            self::ADMIN_USER => EntityType::ADMIN_USER,
            self::MEMBER => EntityType::MEMBER,
            self::TRANSACTION => EntityType::TRANSACTION,
            self::CREDIT_LIMIT_CONFIG => EntityType::CREDIT_LIMIT_CONFIG,
            self::INSTANCE_CONFIG => EntityType::INSTANCE_CONFIG,
        };
    }
}
