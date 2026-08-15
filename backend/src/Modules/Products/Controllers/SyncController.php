<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Modules\Terminals\Enums\SyncStream;
use App\Modules\Terminals\Services\TerminalSyncCursorService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    use JsonResponder;

    public function __construct(
        private CategoriesService $categoriesService,
        private ProductsService $productsService,
        private TerminalSyncCursorService $syncCursors,
    ) {}

    public function categories(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Client sends milliseconds timestamp
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        // ADR-0041: observed, never acted on — the delta below is unchanged.
        $this->syncCursors->observe($request->getAttribute('terminal_id'), SyncStream::CATEGORIES, $since);

        $result = $this->categoriesService->syncSince($since);

        return $this->json($response, $result->toArray('categories'));
    }

    public function products(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Client sends milliseconds timestamp
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        $this->syncCursors->observe($request->getAttribute('terminal_id'), SyncStream::PRODUCTS, $since);

        $result = $this->productsService->syncSince($since);

        return $this->json($response, $result->toArray('products'));
    }
}
