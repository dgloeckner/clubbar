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
 * #119 added InvalidQueryParameterException, and #446 aligned
 * ValidationException to the same `messages` map — the shape every
 * `Validator` call site and list endpoint uses for a rejected field, and
 * which the frontend reads to say which field is wrong.
 */
class ErrorHandlerTest extends TestCase
{
    private function handle(\Throwable $thrown, bool $debug = false): ResponseInterface
    {
        $handler = new ErrorHandler(new Logger(sys_get_temp_dir() . '/clubbar-errorhandler-test'), debug: $debug);

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

    public function test_a_validation_failure_stays_422_with_the_shared_messages_shape(): void
    {
        $response = $this->handle(new ValidationException('invalid', ['iban' => ['bad checksum']]));

        $this->assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame(['iban' => ['bad checksum']], $body['messages']);
        $this->assertArrayNotHasKey('errors', $body);
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

    // ── What a 500 is allowed to say (#107) ────────────────
    //
    // The message of an unhandled throwable is written by whatever blew up,
    // not by us. A PDOException in particular spells out the SQLSTATE, the
    // table and the column — `Duplicate entry 'abc' for key members.card_uid`
    // hands a caller probing the API a free schema map, and can echo back a
    // fragment of another member's data embedded in the constraint message.
    // The full text is already in the log; the response gets a fixed sentence.

    public function test_a_500_does_not_echo_the_exception_message(): void
    {
        $response = $this->handle(new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ABC123' for key 'members.card_uid'"
        ));

        $body = $this->decode($response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('internal_error', $body['error']);
        $this->assertStringNotContainsString('SQLSTATE', $body['message']);
        $this->assertStringNotContainsString('card_uid', $body['message']);
        $this->assertStringNotContainsString('ABC123', $body['message']);
        $this->assertNotSame('', $body['message']);
    }

    public function test_the_generic_500_message_is_the_same_whatever_failed(): void
    {
        $first  = $this->decode($this->handle(new \RuntimeException('connection refused')));
        $second = $this->decode($this->handle(new \LogicException('array key missing')));

        $this->assertSame($first['message'], $second['message']);
    }

    public function test_debug_mode_still_shows_the_real_500_message(): void
    {
        $response = $this->handle(new \RuntimeException('boom'), debug: true);

        $body = $this->decode($response);
        $this->assertSame('boom', $body['message']);
        $this->assertArrayHasKey('trace', $body);
    }

    /**
     * Only 500 is muted. The other statuses on this branch carry messages the
     * caller is meant to read — Slim's own "Not found", and the
     * InvalidArgumentException a service raises for a rejected upload, which
     * the mandate form renders next to the field.
     */
    public function test_a_422_still_reports_why_the_input_was_rejected(): void
    {
        $response = $this->handle(new \InvalidArgumentException("Unsupported file type 'text/html'."));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame("Unsupported file type 'text/html'.", $this->decode($response)['message']);
    }

    public function test_a_slim_404_still_reports_its_own_message(): void
    {
        $notFound = new \Slim\Exception\HttpNotFoundException(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/nope')
        );

        $response = $this->handle($notFound);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($notFound->getMessage(), $this->decode($response)['message']);
    }
}
