<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Controllers;

use App\Modules\Registrations\Controllers\PublicController;
use App\Modules\Registrations\DTOs\RegistrationContextDto;
use App\Modules\Registrations\DTOs\RegistrationReceiptDto;
use App\Modules\Registrations\Services\RegistrationsService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * What the public endpoint accepts, and what it refuses before the service ever
 * sees it.
 *
 * The service has its own tests for the gate and the sealing; this one is about
 * the boundary — a request arriving from the internet, whose body is whatever
 * somebody chose to send.
 */
final class PublicControllerTest extends TestCase
{
    private PublicController $controller;
    private RecordingRegistrationsService $service;

    protected function setUp(): void
    {
        // The Validator's `unique:` rule needs a PDO handle; nothing this
        // controller validates uses it, but the constructor requires one.
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->service = new RecordingRegistrationsService();
        $this->controller = new PublicController($this->service, new Validator($db));
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/public/registrations')
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/json');

        return $this->controller->store($request, new Response());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validBody(array $overrides = []): array
    {
        return $overrides + [
            'secret' => 'a-secret',
            'first_name' => 'Lena',
            'last_name' => 'Brandt',
            'email' => 'lena@example.org',
            'date_of_birth' => '2010-04-02',
            'preferred_language' => 'de',
            'iban' => 'DE89370400440532013000',
        ];
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_valid_submission_is_created(): void
    {
        $response = $this->post($this->validBody());

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('reg-1', $body['id']);
        self::assertArrayHasKey('mandate_reference', $body);
    }

    /**
     * The secret travels in the body, not the path. A request line is written
     * to every access log in front of the installation; a body is written to
     * none of them, and this poster stays on a wall for years.
     */
    public function test_the_secret_is_taken_from_the_body_and_passed_through(): void
    {
        $this->post($this->validBody(['secret' => 'the-poster-secret']));

        self::assertSame('the-poster-secret', $this->service->lastSecret);
    }

    public function test_a_missing_secret_still_reaches_the_service_to_be_refused_there(): void
    {
        $body = $this->validBody();
        unset($body['secret']);

        $this->post($body);

        // The controller does not short-circuit: one place decides what a bad
        // secret means, and it is the place that also meters the attempt.
        self::assertSame('', $this->service->lastSecret);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidFields')]
    public function test_a_rejected_field_is_a_422_naming_it(array $overrides, string $field): void
    {
        $response = $this->post($this->validBody($overrides));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertArrayHasKey($field, $body['messages'], "expected {$field} to be named");
        self::assertNull($this->service->lastPayload, 'nothing should reach the service');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFields(): array
    {
        return [
            'no first name' => [['first_name' => ''], 'first_name'],
            'no last name' => [['last_name' => ''], 'last_name'],
            'malformed email' => [['email' => 'not-an-address'], 'email'],
            'no email' => [['email' => ''], 'email'],
            'birth date in the future' => [['date_of_birth' => '2099-01-01'], 'date_of_birth'],
            'no birth date' => [['date_of_birth' => ''], 'date_of_birth'],
            'unsupported language' => [['preferred_language' => 'kl'], 'preferred_language'],
            'iban failing mod-97' => [['iban' => 'DE89370400440532013001'], 'iban'],
            'iban that is not one' => [['iban' => 'hello'], 'iban'],
            'no iban' => [['iban' => ''], 'iban'],
            'over-long account holder' => [['account_holder_name' => str_repeat('x', 71)], 'account_holder_name'],
        ];
    }

    /** The Kontoinhaber and the phone are optional, and absence is not an error. */
    public function test_the_optional_fields_may_be_absent(): void
    {
        $response = $this->post($this->validBody());

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($this->service->lastPayload['account_holder_name'] ?? null);
        self::assertNull($this->service->lastPayload['phone'] ?? null);
    }

    public function test_the_optional_fields_are_passed_through_when_given(): void
    {
        $this->post($this->validBody([
            'account_holder_name' => 'Petra Brandt',
            'phone' => '+49 69 123456',
        ]));

        self::assertSame('Petra Brandt', $this->service->lastPayload['account_holder_name']);
        self::assertSame('+49 69 123456', $this->service->lastPayload['phone']);
    }

    /**
     * The honeypot is not validated — it is passed through, because the service
     * is what decides to swallow it. Validating it would turn a silent trap
     * into a 422 that tells the bot exactly which field gave it away.
     */
    public function test_a_filled_honeypot_is_not_a_validation_error(): void
    {
        $response = $this->post($this->validBody([RegistrationsService::HONEYPOT_FIELD => 'spam']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('spam', $this->service->lastPayload[RegistrationsService::HONEYPOT_FIELD]);
    }

    /**
     * A public endpoint is shouted at by things that are not browsers. An
     * oversized body is refused before it is interpreted, so a megabyte of
     * junk costs a length check rather than a parse.
     */
    public function test_an_oversized_body_is_refused_before_it_is_parsed(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/public/registrations')
            ->withParsedBody($this->validBody())
            ->withHeader('Content-Length', (string) (PublicController::MAX_BODY_BYTES + 1));

        $response = $this->controller->store($request, new Response());

        self::assertSame(413, $response->getStatusCode());
        self::assertNull($this->service->lastPayload);
    }

    /** A body that is not an object at all — junk, or an array. */
    public function test_a_non_object_body_is_refused(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/public/registrations')
            ->withParsedBody(null);

        $response = $this->controller->store($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->service->lastPayload);
    }


    // ── the entry lookup (#781) ──────────────────────────────────────────

    /** @param array<string, mixed>|null $body */
    private function contextRequest(?array $body): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/public/registrations/context')
            ->withParsedBody($body)
            ->withHeader('Content-Type', 'application/json');

        return $this->controller->context($request, new Response());
    }

    public function test_the_context_answers_what_the_page_needs_to_render(): void
    {
        $response = $this->contextRequest(['secret' => 'a-secret']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertTrue($body['available']);
        self::assertSame('https://club.example/Anmeldung.pdf', $body['document_url']);
        self::assertSame(['de', 'en'], $body['languages']);
    }

    /**
     * The credential is printed on a wall and may still be there in two years.
     * It travels in the body so it never reaches an access log — the page reads
     * it from the URL fragment, which no browser sends.
     */
    public function test_the_secret_is_taken_from_the_body(): void
    {
        $this->contextRequest(['secret' => 'poster-secret-value']);

        self::assertSame('poster-secret-value', $this->service->lastSecret);
    }

    public function test_a_body_that_is_not_an_object_is_an_absent_secret(): void
    {
        $this->contextRequest(null);

        // Passed through as empty rather than short-circuited here: one place
        // decides what a bad secret means, and it is the place that meters it.
        self::assertSame('', $this->service->lastSecret);
    }

    /**
     * An unavailable club is the *answer*, not a refusal — and it arrives in the
     * same shape a refused submission does, so the page renders it identically
     * whether it learned about it on load or by racing an admin mid-form.
     */
    public function test_an_unavailable_club_is_a_200_carrying_its_own_reason(): void
    {
        $this->service->nextContext = new RegistrationContextDto(
            available: false,
            reason: 'registration_disabled',
            message: 'Beta-Phase schon voll',
            documentUrl: 'https://club.example/Anmeldung.pdf',
            languages: ['de'],
        );

        $response = $this->contextRequest(['secret' => 'a-secret']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertFalse($body['available']);
        self::assertSame('registration_disabled', $body['reason']);
        self::assertSame('Beta-Phase schon voll', $body['message']);
    }

    /**
     * A page still showing yesterday's answer is the one thing this endpoint
     * exists to prevent: the club's switch takes effect the moment it is flipped.
     */
    public function test_the_context_is_never_cached(): void
    {
        self::assertSame(
            'no-store',
            $this->contextRequest(['secret' => 'a-secret'])->getHeaderLine('Cache-Control'),
        );
    }
}

/** Records what the controller passed on, so the boundary can be asserted. */
final class RecordingRegistrationsService extends RegistrationsService
{
    public string $lastSecret = '<never called>';
    /** @var array<string, mixed>|null */
    public ?array $lastPayload = null;

    public function __construct()
    {
        // Deliberately not calling the parent constructor: this stand-in
        // answers for the service without needing its eight collaborators.
    }

    public function submit(string $presentedSecret, array $data, string $ip): RegistrationReceiptDto
    {
        $this->lastSecret = $presentedSecret;
        $this->lastPayload = $data;

        return new RegistrationReceiptDto(id: 'reg-1', mandateReference: str_repeat('a', 32));
    }

    /** Overridden for the same reason `submit()` is: this stub has no collaborators. */
    public ?RegistrationContextDto $nextContext = null;

    public function context(string $presentedSecret, string $ip): RegistrationContextDto
    {
        $this->lastSecret = $presentedSecret;

        return $this->nextContext ?? new RegistrationContextDto(
            available: true,
            reason: null,
            message: null,
            documentUrl: 'https://club.example/Anmeldung.pdf',
            languages: ['de', 'en'],
        );
    }
}
