<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Controllers;

use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Registrations\Services\RegistrationsService;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The one endpoint an anonymous phone reaches (ADR-0052).
 *
 * Thin, like every controller here — but with two jobs the admin controllers do
 * not have, both because of who is calling.
 *
 * **It refuses a body before interpreting it.** A public URL is shouted at by
 * things that are not browsers, and parsing a megabyte of junk to discover it
 * is junk is work an anonymous caller should not be able to ask for.
 *
 * **It does not decide what a bad secret means.** The secret is passed through
 * to the service untouched, including when it is absent. One place decides
 * whether a presented secret is good, and it is the place that also meters the
 * attempt — a controller short-circuit would be a second opinion that skips the
 * meter.
 */
class PublicController
{
    use JsonResponder;

    /**
     * The largest body worth reading.
     *
     * Generous for the form it carries — a few hundred bytes — and small enough
     * that refusing costs a header check.
     */
    public const MAX_BODY_BYTES = 16384;

    /**
     * Pattern 001: the rules are a declarative table, not a sequence of `if`s.
     *
     * `secret` is deliberately absent: it is a credential, not a field, and
     * naming it in a validation error would tell a prober the shape of what
     * they are guessing.
     *
     * The honeypot is absent for a related reason — validating it would turn a
     * silent trap into a 422 that names the field that gave the bot away.
     */
    private const FIELD_RULES = [
        'first_name' => ['required', 'string', 'max:100'],
        'last_name' => ['required', 'string', 'max:100'],
        'email' => ['required', 'email', 'max:255'],
        'phone' => ['nullable', 'string', 'max:20'],
        'date_of_birth' => ['required', 'date', 'past_date'],
        'preferred_language' => ['required', 'string'],
        'account_holder_name' => ['nullable', 'string', 'max:70'],
        'iban' => ['required', 'string', 'iban', 'max:34'],
    ];

    public function __construct(
        private RegistrationsService $registrations,
        private Validator $validator,
    ) {}

    /**
     * POST /api/public/registrations/context — what the page needs on load.
     *
     * A POST for a read, and that is the point: the secret travels in the body,
     * never in the URL. The onboarding page reads it from the URL **fragment**,
     * which a browser does not send to any server, and puts it here — so the
     * credential printed on a clubhouse wall never reaches an access log, in
     * front of the installation or behind it.
     *
     * An unavailable club is a `200` carrying `available: false`, not a
     * refusal. The page is asking a question and this is the answer; only a bad
     * secret is a refusal, and it is the same uniform 404 a submission gets.
     */
    public function context(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $secret = is_array($body) ? (string) ($body['secret'] ?? '') : '';

        $context = $this->registrations->context($secret, $this->clientIp($request));

        // Not cached. The club's paused screen and its reason change the moment
        // an admin flips the switch, and a page still showing yesterday's answer
        // is the one thing this endpoint exists to prevent.
        return $this->json($response, $context->toArray())
            ->withHeader('Cache-Control', 'no-store');
    }

    public function store(Request $request, Response $response): Response
    {
        $declaredLength = (int) ($request->getHeaderLine('Content-Length') ?: '0');
        if ($declaredLength > self::MAX_BODY_BYTES) {
            return $this->json($response, [
                'error' => 'payload_too_large',
                'message' => 'The request body is larger than this endpoint accepts.',
            ], 413);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->validationFailed($response, [], 'A JSON object is required.');
        }

        $body = self::withBlanksAsNull($body);

        $rules = self::FIELD_RULES;
        $rules['preferred_language'][] = 'in:' . implode(',', array_column(SupportedLanguage::cases(), 'value'));

        if (!$this->validator->validate($body, $rules)) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $receipt = $this->registrations->submit(
            (string) ($body['secret'] ?? ''),
            [
                'first_name' => $body['first_name'],
                'last_name' => $body['last_name'],
                'email' => $body['email'],
                'phone' => $body['phone'] ?? null,
                'date_of_birth' => $body['date_of_birth'],
                'preferred_language' => $body['preferred_language'],
                'account_holder_name' => $body['account_holder_name'] ?? null,
                'iban' => $body['iban'],
                RegistrationsService::HONEYPOT_FIELD => $body[RegistrationsService::HONEYPOT_FIELD] ?? '',
            ],
            $this->clientIp($request),
        );

        // `no-store`, and it is not decoration. The body carries the member's
        // own filled mandate, complete with their full IBAN, on a response that
        // travels through whatever the applicant's phone, their network and the
        // club's reverse proxy would otherwise be entitled to keep. It is the
        // one response in this application whose body is a bank detail.
        return $this->json($response, $receipt->toArray(), 201)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * A browser sends an untouched optional field as `""`, and `nullable` reads
     * that as present-and-empty rather than absent. The admin controllers
     * normalise the same way.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function withBlanksAsNull(array $body): array
    {
        foreach ($body as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $body[$key] = null;
            }
        }

        return $body;
    }

    private function clientIp(Request $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
