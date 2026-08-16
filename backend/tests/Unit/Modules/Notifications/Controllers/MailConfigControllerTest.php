<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Notifications\Controllers\MailConfigController;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\TestMailService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * {@see MailConfigController::rotateCronSecret()} (#473) — the step-up gate and
 * the shape of a one-time secret response. {@see MailConfigService} owns the
 * generation/hashing/precedence logic and is tested on its own; this file is
 * about what the controller does with a caller who has, or has not, just
 * re-proven who they are.
 */
class MailConfigControllerTest extends TestCase
{
    private MailConfigService $service;
    private StepUpAuthService $stepUp;
    private MailConfigController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(MailConfigService::class);
        $this->service->method('transportStatus')->willReturn(\App\Shared\Mail\MailTransportStatus::unconfigured());
        $this->service->method('getConfig')->willReturn($this->config());
        $this->stepUp = $this->createMock(StepUpAuthService::class);
        $this->controller = new MailConfigController(
            $this->service,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(TestMailService::class),
            $this->stepUp,
        );
    }

    private function config(): \App\Modules\Notifications\DTOs\MailConfigDto
    {
        return \App\Modules\Notifications\DTOs\MailConfigDto::fromRow(['sender_address' => 'bar@example.org']);
    }

    /** A POST carrying a parsed body and an authenticated caller. */
    private function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/mail-config/cron-secret/rotate')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1', 'email' => 'admin@example.com']);
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_rotates_and_returns_the_plaintext_once_when_the_step_up_holds(): void
    {
        $this->stepUp->method('verify')->willReturn(true);
        $this->service->expects($this->once())
            ->method('rotateCronSecret')
            ->with('admin-1')
            ->willReturn('the-new-secret');

        $response = $this->controller->rotateCronSecret($this->post(['current_password' => 'correct']), new Response());
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('the-new-secret', $body['cron_secret']);
        $this->assertStringContainsString('will not be shown again', $body['message']);
    }

    public function test_refuses_a_failed_step_up_without_rotating_anything(): void
    {
        $this->stepUp->method('verify')->willReturn(false);
        $this->service->expects($this->never())->method('rotateCronSecret');

        $response = $this->controller->rotateCronSecret($this->post(['current_password' => 'wrong']), new Response());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_a_body_with_no_password_before_verifying_anything(): void
    {
        $this->stepUp->expects($this->never())->method('verify');
        $this->service->expects($this->never())->method('rotateCronSecret');

        $response = $this->controller->rotateCronSecret($this->post([]), new Response());

        $this->assertSame(422, $response->getStatusCode());
    }

    /** A caller with no `admin_user` attribute cannot have proved anything. */
    public function test_refuses_when_the_caller_attribute_is_missing(): void
    {
        $this->stepUp->expects($this->never())->method('verify');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/mail-config/cron-secret/rotate')
            ->withParsedBody(['current_password' => 'correct'])
            ->withAttribute('admin_user_id', 'admin-1');

        $response = $this->controller->rotateCronSecret($request, new Response());

        $this->assertSame(401, $response->getStatusCode());
    }
}
