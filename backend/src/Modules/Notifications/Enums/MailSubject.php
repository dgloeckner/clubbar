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
        };
    }
}
