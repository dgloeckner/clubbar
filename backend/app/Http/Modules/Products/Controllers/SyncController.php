<?php

namespace App\Http\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Products\Services\CategoriesService;
use App\Http\Modules\Products\Services\ProductsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sync API Controller - Products Module
 *
 * Handles terminal sync endpoints for products and categories.
 * Implements delta sync pattern for offline-first terminal.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to services
 *
 * Terminal Sync Endpoints:
 * - GET /api/sync/categories?since={timestamp} - Category delta sync
 * - GET /api/sync/products?since={timestamp} - Product delta sync
 *
 * Authentication:
 * - Requires valid Bearer token (terminal device authentication)
 * - Middleware: AuthenticateTerminalToken
 */
class SyncController extends Controller
{
    /**
     * Initialize controller with product/category services.
     *
     * @param CategoriesService $categoriesService
     * @param ProductsService $productsService
     */
    public function __construct(
        private readonly CategoriesService $categoriesService,
        private readonly ProductsService $productsService,
    ) {}

    /**
     * GET /api/sync/categories - Delta Sync Categories
     *
     * Returns categories modified since given timestamp.
     * Only active categories returned (sorted by display_order).
     *
     * Query Parameters:
     * - since: Unix timestamp (default: 0 for first sync)
     *
     * Response:
     * {
     *   "categories": [CategoryDto],
     *   "cursor": "2026-01-25T14:32:00Z",
     *   "count": 5,
     *   "has_more": false
     * }
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function categories(Request $request): JsonResponse
    {
        // Get since parameter, default to 0 (all categories)
        $since = (int) $request->query('since', 0);

        // Delegate to service
        $result = $this->categoriesService->syncSince($since);

        return response()->json([
            'categories' => array_map(fn($dto) => $dto->toArray(), $result->items),
            'cursor' => $result->cursor,
            'count' => count($result->items),
            'has_more' => $result->hasMore,
        ]);
    }

    /**
     * GET /api/sync/products - Delta Sync Products
     *
     * Returns products modified since given timestamp.
     * Only active products from active categories returned.
     *
     * Query Parameters:
     * - since: Unix timestamp (default: 0 for first sync)
     *
     * Response:
     * {
     *   "products": [ProductDto],
     *   "cursor": "2026-01-25T14:32:00Z",
     *   "count": 42,
     *   "has_more": false
     * }
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function products(Request $request): JsonResponse
    {
        // Get since parameter, default to 0 (all products)
        $since = (int) $request->query('since', 0);

        // Delegate to service
        $result = $this->productsService->syncSince($since);

        return response()->json([
            'products' => array_map(fn($dto) => $dto->toArray(), $result->items),
            'cursor' => $result->cursor,
            'count' => count($result->items),
            'has_more' => $result->hasMore,
        ]);
    }
}
