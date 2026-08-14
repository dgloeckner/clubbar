<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Security\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Security\Controllers\EncryptionKeysController;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\KeyRotationService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `POST /api/admin/encryption-keys`'s `Validator` rejection (#446): a missing
 * field is refused before the step-up credential is even checked.
 */
class EncryptionKeysControllerTest extends TestCase
{
    private EncryptionKeyService $encryptionKeyService;
    private StepUpAuthService $stepUpAuthService;
    private EncryptionKeysController $controller;

    protected function setUp(): void
    {
        $this->encryptionKeyService = $this->createMock(EncryptionKeyService::class);
        $this->stepUpAuthService = $this->createMock(StepUpAuthService::class);

        $this->controller = new EncryptionKeysController(
            $this->encryptionKeyService,
            $this->createMock(KeyRotationService::class),
            $this->stepUpAuthService,
            new Validator($this->createMock(PDO::class)),
        );
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_store_rejects_a_missing_public_key_before_checking_step_up(): void
    {
        $this->stepUpAuthService->expects($this->never())->method('verify');
        $this->encryptionKeyService->expects($this->never())->method('register');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/encryption-keys')
            ->withParsedBody(['key_identifier' => 'key-2026', 'current_password' => 'secret']);

        $response = $this->controller->store($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('public_key', $body['messages']);
    }
}
