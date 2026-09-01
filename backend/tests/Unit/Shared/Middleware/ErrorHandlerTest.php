<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Middleware;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\TooManyAttemptsException;
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

    // ── A throttle is not a refusal ────────────────────────
    //
    // 429, with how long to wait, in the same shape `RateLimitMiddleware`
    // writes when it refuses before the request is understood. The distinction
    // from a 409 is the point: "not right now" is answered by waiting, "this
    // cannot be done" by changing something.

    public function test_a_throttle_is_429_with_a_retry_after(): void
    {
        $response = $this->handle(new TooManyAttemptsException(900, 'Too many attempts.'));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->getHeaderLine('Retry-After'));

        $body = $this->decode($response);
        $this->assertSame('too_many_attempts', $body['error']);
        $this->assertSame(900, $body['retry_after_seconds']);
        $this->assertArrayNotHasKey('reason', $body, 'a throttle names no business rule');
    }

    public function test_a_business_rule_violation_stays_409(): void
    {
        $response = $this->handle(new BusinessRuleException(
            BusinessRuleReason::TRANSACTIONS_ALREADY_SETTLED,
            'already settled',
        ));

        $this->assertSame(409, $response->getStatusCode());
    }

    // ── What a refusal says, and to whom (#757) ────────────
    //
    // `message` is English, written for this log line and for a raw API
    // caller. The admin panel renders in the admin's own language, so it needs
    // the *reason* — a stable code — and the values its sentence interpolates.
    // Sending only the sentence is how "Cannot anonymize: outstanding balance
    // of €7.50" reached a German screen.

    public function test_a_business_rule_violation_names_a_translatable_reason(): void
    {
        $response = $this->handle(new BusinessRuleException(
            BusinessRuleReason::MEMBER_BALANCE_OUTSTANDING,
            'Cannot anonymize: outstanding balance of €7.50',
            ['balance_cents' => 750],
        ));

        $body = $this->decode($response);

        $this->assertSame('business_rule_violation', $body['error']);
        $this->assertSame('member_balance_outstanding', $body['reason']);
        $this->assertSame(['balance_cents' => 750], $body['params']);
        // Unchanged: the log and every existing API assertion still read it.
        $this->assertSame('Cannot anonymize: outstanding balance of €7.50', $body['message']);
    }

    public function test_a_refusal_with_nothing_to_interpolate_omits_params(): void
    {
        // An empty map would be noise in every response body that has no
        // values to carry, which is most of them.
        $body = $this->decode($this->handle(new BusinessRuleException(
            BusinessRuleReason::MEMBER_ALREADY_ANONYMIZED,
            'Member already anonymized',
        )));

        $this->assertSame('member_already_anonymized', $body['reason']);
        $this->assertArrayNotHasKey('params', $body);
    }

    public function test_only_a_business_rule_carries_a_reason(): void
    {
        // A 404 or a 500 has no rule to name, and a client must not read a
        // stale `reason` off one.
        $this->assertArrayNotHasKey('reason', $this->decode($this->handle(new NotFoundException('nope'))));
        $this->assertArrayNotHasKey('reason', $this->decode($this->handle(new \RuntimeException('boom'))));
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
