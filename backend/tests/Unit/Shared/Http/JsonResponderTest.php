<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\JsonResponder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;

class JsonResponderTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = new class {
            use JsonResponder;

            public function respond(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
            {
                return $this->json($response, $data, $status);
            }

            public function reject(ResponseInterface $response, array $messages, ?string $message = null): ResponseInterface
            {
                return $this->validationFailed($response, $messages, $message);
            }
        };
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    public function test_it_writes_the_payload_as_json(): void
    {
        $response = $this->controller->respond($this->response(), ['ok' => true]);

        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }

    public function test_it_sets_the_json_content_type(): void
    {
        $response = $this->controller->respond($this->response(), []);

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_it_defaults_to_200(): void
    {
        $this->assertSame(200, $this->controller->respond($this->response(), [])->getStatusCode());
    }

    public function test_it_honours_an_explicit_status(): void
    {
        $this->assertSame(201, $this->controller->respond($this->response(), [], 201)->getStatusCode());
    }

    /**
     * Umlauts are the norm in member names; escaping them to \uXXXX bloats
     * every list response and makes the logs unreadable.
     */
    public function test_it_leaves_unicode_unescaped(): void
    {
        $response = $this->controller->respond($this->response(), ['name' => 'Jörg Müller']);

        $this->assertSame('{"name":"Jörg Müller"}', (string) $response->getBody());
    }

    /**
     * The single emitter every `Validator` call site goes through (#446):
     * a named field examined and rejected is always this shape, whichever
     * controller produced it.
     */
    public function test_validation_failed_is_422_with_the_shared_shape(): void
    {
        $response = $this->controller->reject($this->response(), ['email' => ['Email already exists']]);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame(['email' => ['Email already exists']], $body['messages']);
        $this->assertArrayNotHasKey('message', $body);
    }

    /**
     * The terminal API's shared `ErrorResponse` requires `message` on every
     * error, unlike the admin API's `ValidationErrorResponse`. Passing one
     * adds it without disturbing the default shape every other caller relies
     * on.
     */
    public function test_validation_failed_carries_an_optional_top_level_message(): void
    {
        $response = $this->controller->reject(
            $this->response(),
            ['preferred_language' => ['preferred_language is required']],
            'preferred_language is required',
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('preferred_language is required', $body['message']);
        $this->assertSame(['preferred_language is required'], $body['messages']['preferred_language']);
    }
}
