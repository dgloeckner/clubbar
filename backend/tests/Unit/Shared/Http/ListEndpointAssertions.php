<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Shared plumbing for the list-endpoint controller tests (#119): build a GET
 * with query parameters, and read a JSON body back.
 */
trait ListEndpointAssertions
{
    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, array $query = [], ?string $adminId = 'admin-1'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withQueryParams($query)
            ->withAttribute('admin_user_id', $adminId);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }
}
