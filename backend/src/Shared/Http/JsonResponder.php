<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * The one JSON writer.
 *
 * Seventeen controllers each carried a private copy of this four-line method
 * (issue #119). They agreed today, but nothing kept them agreeing: a change to
 * the encoding flags or the content type had to be made seventeen times or not
 * at all. Controllers `use` this trait instead of reimplementing it.
 */
trait JsonResponder
{
    protected function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * The one 422 body for a named field that was examined and rejected
     * (issue #446). Every `Validator` call site, and everything that used to
     * hand-write its own field map, goes through this instead of literals
     * that agree today but nothing keeps agreeing.
     *
     * `$message` is omitted by default — the admin API's `ValidationErrorResponse`
     * has never required it, and adding it everywhere would be one more thing
     * for 46 call sites to agree on for no reader's benefit. Pass one only
     * where a caller's contract requires it, e.g. the terminal API's shared
     * `ErrorResponse` base, which every terminal error carries a `message` for.
     *
     * @param array<string, list<string>> $messages
     */
    protected function validationFailed(Response $response, array $messages, ?string $message = null): Response
    {
        $body = ['error' => 'validation_failed', 'messages' => $messages];
        if ($message !== null) {
            $body['message'] = $message;
        }

        return $this->json($response, $body, 422);
    }
}
