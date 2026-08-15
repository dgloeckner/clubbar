<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Http\JsonResponder;
use App\Shared\Mail\MailLayout;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Read and edit the club-editable mail settings (ADR-0038).
 *
 * The response also carries the *transport* state, which is not editable here
 * and never will be — the DSN is a secret in `config.php`. It is included
 * because the question an admin actually has is "will announcements go out?",
 * and answering it needs both halves. The DSN itself is never returned, only a
 * redacted description of where mail goes.
 */
class MailConfigController
{
    use JsonResponder;

    public function __construct(
        private MailConfigService $mailConfigService,
        private Validator $validator,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->payload());
    }

    public function update(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if ($refusal = self::weeklyRefusal($body)) {
            return $this->validationFailed($response, $refusal);
        }

        // `max:` measures a string's length rather than its value, so a batch
        // size arriving as "2000" would sail past a ceiling of 1000 and be
        // stored above it. Normalised here, before the rules run, so the
        // validator and the column see the same number.
        // Only a string of digits is normalised: casting anything looser here
        // would turn "2.5" into 2 and walk straight past the `integer` rule
        // that exists to reject it (#117).
        if (isset($body['drain_batch_size']) && is_string($body['drain_batch_size'])
            && preg_match('/^\d+$/', trim($body['drain_batch_size'])) === 1) {
            $body['drain_batch_size'] = (int) trim($body['drain_batch_size']);
        }

        $rules = [
            'sender_name' => ['string', 'max:120'],
            'sender_address' => ['email', 'max:255'],
            'reply_to_address' => ['nullable', 'email', 'max:255'],
            'header_style' => ['in:' . implode(',', MailLayout::HEADER_STYLES)],
            'footer_org_name' => ['string', 'max:200'],
            'footer_address_line' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'cron_interval' => ['in:' . implode(',', array_column(CronInterval::cases(), 'value'))],
            'drain_batch_size' => ['integer', 'min:1', 'max:' . MailConfigDto::MAX_DRAIN_BATCH_SIZE],
        ];

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $config = $this->mailConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update mail configuration'], 500);
        }

        return $this->json($response, $this->payload());
    }

    /**
     * `weekly` is refused with its reason, not with "must be one of".
     *
     * It is the one rejected value somebody will deliberately try, because it
     * is what their tariff offers — and the generic enum message would read as
     * an arbitrary limitation of this application rather than as the thing it
     * is: a granularity at which the club's own § 7 Abs. 3 announcement promise
     * stops holding. ADR-0039 decision 5 spells out the arithmetic. Whoever
     * hits this needs the reasoning, because the remedy is a different hosting
     * tariff or an external scheduler, and neither is discoverable from
     * "invalid value".
     *
     * @param array<string,mixed> $body
     * @return array<string,string>|null
     */
    private static function weeklyRefusal(array $body): ?array
    {
        $submitted = $body['cron_interval'] ?? null;

        if (!is_string($submitted) || strtolower(trim($submitted)) !== CronInterval::REFUSED) {
            return null;
        }

        return ['cron_interval' => 'A weekly scheduler cannot keep the seven-day announcement promise of '
            . 'Nutzungsordnung § 7 Abs. 3: an announcement queued shortly after a weekly run leaves up to seven '
            . 'days later and would reach the member on the collection date itself. Schedule the drain at least '
            . 'daily — every 15 minutes is what the setup instructions recommend — or drive the URL trigger from '
            . 'an external scheduler.'];
    }

    private function payload(): array
    {
        $status = $this->mailConfigService->transportStatus();

        return $this->mailConfigService->getConfig()->toArray() + [
            'transport' => [
                'configured' => $status->configured,
                'valid' => $status->valid,
                'summary' => $status->summary,
                'error' => $status->error,
            ],
            'can_send' => $this->mailConfigService->canSend(),
        ];
    }
}
