<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    use JsonResponder;

    public function __construct(
        private CategoriesService $categoriesService,
        private ProductsService $productsService,
    ) {}

    public function categories(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Client sends milliseconds timestamp
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        $result = $this->categoriesService->syncSince($since);

        return $this->json($response, $result->toArray('categories'));
    }

    public function products(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Client sends milliseconds timestamp
        $since = isset($params['since']) ? (int) $params['since'] : 0;

        $result = $this->productsService->syncSince($since);

        return $this->json($response, $result->toArray('products'));
    }
}
