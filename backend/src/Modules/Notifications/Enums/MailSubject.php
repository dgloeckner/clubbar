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
    public function auditEntityType(): EntityType
    {
        return match ($this) {
            self::SETTLEMENT => EntityType::SETTLEMENT,
            self::ENCRYPTION_KEY => EntityType::ENCRYPTION_KEY,
            self::TERMINAL => EntityType::TERMINAL,
            self::ADMIN_USER => EntityType::ADMIN_USER,
            self::MEMBER => EntityType::MEMBER,
        };
    }
}
