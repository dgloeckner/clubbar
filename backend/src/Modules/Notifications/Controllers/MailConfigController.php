<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

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

        $rules = [
            'sender_name' => ['string', 'max:120'],
            'sender_address' => ['email', 'max:255'],
            'reply_to_address' => ['nullable', 'email', 'max:255'],
            'header_style' => ['in:' . implode(',', MailLayout::HEADER_STYLES)],
            'footer_org_name' => ['string', 'max:200'],
            'footer_address_line' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:255'],
        ];

        if (!$this->validator->validate($body, $rules)) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $config = $this->mailConfigService->updateConfig($body, $adminId);

        if (!$config) {
            return $this->json($response, ['error' => 'Failed to update mail configuration'], 500);
        }

        return $this->json($response, $this->payload());
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
