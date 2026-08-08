<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Middleware;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Logging\Logger;
use App\Shared\Middleware\ErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The error middleware's translation of domain exceptions into responses.
 *
 * #119 added InvalidQueryParameterException, which is the only one that
 * carries a `messages` map — the shape list endpoints have always used for a
 * rejected `per_page`, and which the frontend reads to say which field is
 * wrong.
 */
class ErrorHandlerTest extends TestCase
{
    private function handle(\Throwable $thrown): ResponseInterface
    {
        $handler = new ErrorHandler(new Logger(sys_get_temp_dir() . '/clubbar-errorhandler-test'), debug: false);

        $next = new class ($thrown) implements RequestHandlerInterface {
            public function __construct(private \Throwable $thrown) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        };

        return $handler->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/members'),
            $next,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_rejected_query_parameter_becomes_400_invalid_request(): void
    {
        $response = $this->handle(new InvalidQueryParameterException(
            'per_page must not exceed 100',
            ['per_page' => ['per_page must not exceed 100']],
        ));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);
        $this->assertSame('invalid_request', $body['error']);
        $this->assertSame('per_page must not exceed 100', $body['message']);
        $this->assertSame(['per_page' => ['per_page must not exceed 100']], $body['messages']);
    }

    public function test_the_messages_map_is_keyed_by_the_parameter_the_caller_used(): void
    {
        $response = $this->handle(new InvalidQueryParameterException('bad', ['limit' => ['bad']]));

        $this->assertArrayHasKey('limit', $this->decode($response)['messages']);
    }

    public function test_a_validation_failure_stays_422_with_errors_not_messages(): void
    {
        $response = $this->handle(new ValidationException('invalid', ['iban' => ['bad checksum']]));

        $this->assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame(['iban' => ['bad checksum']], $body['errors']);
        $this->assertArrayNotHasKey('messages', $body);
    }

    public function test_a_not_found_stays_404(): void
    {
        $response = $this->handle(new NotFoundException('Member not found'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $this->decode($response)['error']);
    }

    public function test_a_business_rule_violation_stays_409(): void
    {
        $response = $this->handle(new BusinessRuleException('already settled'));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_an_unexpected_error_becomes_500(): void
    {
        $response = $this->handle(new \RuntimeException('boom'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('internal_error', $this->decode($response)['error']);
    }
}
