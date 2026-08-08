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
}
