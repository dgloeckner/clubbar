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
}
