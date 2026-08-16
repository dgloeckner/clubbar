<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\StatementCadence;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\TestMailService;
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
        private TestMailService $testMailService,
        private StepUpAuthService $stepUpAuthService,
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
        foreach (['drain_batch_size', 'drain_budget_seconds'] as $numeric) {
            if (isset($body[$numeric]) && is_string($body[$numeric])
                && preg_match('/^\d+$/', trim($body[$numeric])) === 1) {
                $body[$numeric] = (int) trim($body[$numeric]);
            }
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
            'drain_budget_seconds' => [
                'integer',
                'min:' . MailConfigDto::MIN_DRAIN_BUDGET_SECONDS,
                'max:' . MailConfigDto::MAX_DRAIN_BUDGET_SECONDS,
            ],
            'statement_cadence' => ['in:' . implode(',', array_column(StatementCadence::cases(), 'value'))],
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
     * Send the *requesting admin* a test mail (#407).
     *
     * The recipient comes from the session, never from the body: an endpoint
     * that mails an arbitrary address on request is an authenticated open relay
     * on the club's own domain. See {@see TestMailService} for why this is the
     * one place that sends from a request without contradicting ADR-0038
     * rule 3.
     *
     * Always 200. Every outcome here — no transport, bad credentials, refused
     * relay — is the answer the admin pressed the button to get, and an error
     * status would put the diagnosis behind a generic failure banner.
     */
    public function sendTestMail(Request $request, Response $response): Response
    {
        return $this->json(
            $response,
            $this->testMailService->sendTo((string) $request->getAttribute('admin_user_id'))
        );
    }

    /**
     * Generate a new URL-trigger secret, shown exactly once (#473).
     *
     * Mints a credential, so it carries the same step-up as issuing a terminal
     * token: the caller re-proves their own password (and TOTP, if they have
     * it enabled) before this writes anything. Once written it is the only
     * secret {@see \App\Modules\Notifications\Controllers\CronController}
     * accepts — `config.php`'s `cron.secret`, if this installation had one,
     * stops mattering from this point on.
     */
    public function rotateCronSecret(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, ['current_password' => ['required', 'string']])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        if (!$this->requireStepUp($request, $response, $body, $failed)) {
            return $failed;
        }

        $plaintext = $this->mailConfigService->rotateCronSecret($adminId);

        return $this->json($response, [
            'cron_secret' => $plaintext,
            'message' => 'Secret rotated. It will not be shown again — copy it into your scheduler now; '
                . 'the previous secret, panel-rotated or from config.php, no longer works.',
        ] + $this->payload());
    }

    /** Shared step-up gate; on failure fills $failed with the 401 response. */
    private function requireStepUp(Request $request, Response $response, array $body, ?Response &$failed): bool
    {
        $caller = $request->getAttribute('admin_user');

        if ($caller !== null && $this->stepUpAuthService->verify($caller, $body, $request)) {
            return true;
        }

        $failed = $this->json($response, [
            'error' => 'invalid_credentials',
            'message' => 'Re-enter your password (and TOTP code) to rotate the cron secret',
        ], 401);

        return false;
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
