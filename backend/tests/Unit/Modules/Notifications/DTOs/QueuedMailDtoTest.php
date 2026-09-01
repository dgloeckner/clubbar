<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\DTOs;

use App\Modules\Notifications\DTOs\QueuedMailDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use PHPUnit\Framework\TestCase;

/**
 * The notification queue's timestamps, as the browser has to read them.
 *
 * Every column behind this API holds UTC (`Shared\Time\Utc`), and `admin.yaml`
 * declares all three of these fields `format: date-time`. A bare
 * "2026-09-01 19:33:12" is neither: browsers read it as the *reader's* local
 * time, so an invitation queued at 21:33 CEST was listed as 19:33 — with
 * nothing on the screen to suggest the number was wrong.
 */
class QueuedMailDtoTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'mail-uuid',
            'kind' => MailKind::ADMIN_INVITATION->value,
            'subject_id' => 'admin-uuid',
            'member_id' => null,
            'admin_user_id' => 'admin-uuid',
            'admin_display_name' => 'Daniel Getränkewart',
            'recipient' => 'daniel@example.org',
            'language' => 'de',
            'status' => MailStatus::SENT->value,
            'attempts' => 1,
            'last_error' => null,
            'queued_at' => '2026-09-01 19:33:12',
            'next_attempt_at' => null,
            'sent_at' => '2026-09-01 19:45:07',
        ], $overrides);
    }

    public function test_it_labels_every_timestamp_utc(): void
    {
        $array = QueuedMailDto::fromRow($this->row([
            'status' => MailStatus::FAILED->value,
            'next_attempt_at' => '2026-09-01 20:45:07',
        ]))->toArray();

        $this->assertSame('2026-09-01T19:33:12Z', $array['queued_at']);
        $this->assertSame('2026-09-01T20:45:07Z', $array['next_attempt_at']);
        $this->assertSame('2026-09-01T19:45:07Z', $array['sent_at']);
    }

    /**
     * A message that has not left yet has no send time, and the response says
     * so rather than dating it now.
     */
    public function test_it_reports_an_absent_timestamp_as_null(): void
    {
        $array = QueuedMailDto::fromRow($this->row([
            'status' => MailStatus::PENDING->value,
            'sent_at' => null,
        ]))->toArray();

        $this->assertNull($array['sent_at']);
        $this->assertNull($array['next_attempt_at']);
    }

    /** The DTO keeps the stored form; only the response is labelled. */
    public function test_it_keeps_the_stored_value_on_the_dto(): void
    {
        $dto = QueuedMailDto::fromRow($this->row());

        $this->assertSame('2026-09-01 19:33:12', $dto->queuedAt);
        $this->assertSame('2026-09-01 19:45:07', $dto->sentAt);
    }
}
