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
     * @param array<string, list<string>> $messages
     */
    protected function validationFailed(Response $response, array $messages): Response
    {
        return $this->json($response, ['error' => 'validation_failed', 'messages' => $messages], 422);
    }
}
