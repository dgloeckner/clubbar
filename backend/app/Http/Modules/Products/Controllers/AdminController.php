<?php

namespace App\Http\Modules\Products\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Products\Requests\CreateCategoryRequest;
use App\Http\Modules\Products\Requests\CreateProductRequest;
use App\Http\Modules\Products\Requests\ListProductsRequest;
use App\Http\Modules\Products\Requests\UpdateCategoryRequest;
use App\Http\Modules\Products\Requests\UpdateProductRequest;
use App\Http\Modules\Products\Services\CategoriesService;
use App\Http\Modules\Products\Services\ProductsService;
use Illuminate\Http\JsonResponse;

/**
 * Admin API Controller - Products Module
 *
 * Handles admin endpoints for product and category management.
 * Implements CRUD operations with audit logging.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to services
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - Categories: GET, POST, PATCH, DELETE
 * - Products: GET, POST, PATCH, DELETE status
 */
class AdminController extends Controller
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

    // ====== CATEGORIES ENDPOINTS ======

    /**
     * GET /api/admin/categories - List Categories
     *
     * Returns all categories with product count.
     *
     * @return JsonResponse
     */
    public function listCategories(): JsonResponse
    {
        $categories = $this->categoriesService->listCategories();
        return response()->json(['categories' => $categories]);
    }

    /**
     * POST /api/admin/categories - Create Category
     *
     * Creates a new category with multilingual names.
     *
     * @param CreateCategoryRequest $request Validated request
     * @return JsonResponse
     */
    public function storeCategory(CreateCategoryRequest $request): JsonResponse
    {
        $category = $this->categoriesService->createCategory($request->validated());
        return response()->json($category->toArray(), 201);
    }

    /**
     * PATCH /api/admin/categories/{categoryId} - Update Category
     *
     * Updates category names and/or display order.
     *
     * @param string $categoryId
     * @param UpdateCategoryRequest $request Validated request
     * @return JsonResponse
     */
    public function updateCategory(string $categoryId, UpdateCategoryRequest $request): JsonResponse
    {
        try {
            $category = $this->categoriesService->updateCategory($categoryId, $request->validated());
            return response()->json($category->toArray());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/admin/categories/{categoryId}/status - Toggle Category Status
     *
     * Activates or deactivates a category.
     *
     * @param string $categoryId
     * @return JsonResponse
     */
    public function toggleCategoryStatus(string $categoryId): JsonResponse
    {
        try {
            $isActive = request()->boolean('is_active', true);
            $category = $this->categoriesService->toggleStatus($categoryId, $isActive);
            return response()->json($category->toArray());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * DELETE /api/admin/categories/{categoryId} - Delete Category
     *
     * Deletes a category if it has no products.
     *
     * @param string $categoryId
     * @return JsonResponse
     */
    public function deleteCategory(string $categoryId): JsonResponse
    {
        try {
            $this->categoriesService->deleteCategory($categoryId);
            return response()->json(null, 204);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'has_products', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/admin/categories/reorder - Reorder Categories
     *
     * Updates display order for categories via drag-and-drop.
     *
     * @return JsonResponse
     */
    public function reorderCategories(): JsonResponse
    {
        $categoryIds = request()->input('category_ids', []);

        if (empty($categoryIds) || !is_array($categoryIds)) {
            return response()->json(['error' => 'invalid_input', 'message' => 'category_ids must be a non-empty array'], 400);
        }

        $this->categoriesService->reorderCategories($categoryIds);
        return response()->json(['message' => 'Categories reordered']);
    }

    // ====== PRODUCTS ENDPOINTS ======

    /**
     * GET /api/admin/products - List Products
     *
     * Returns paginated product list with filters and sorting.
     *
     * Query Parameters:
     * - page: int (1+)
     * - per_page: int (1-100, default 20)
     * - status: all|active|inactive
     * - category_id: UUID
     * - search: string
     * - sort: name|price|category|created_at
     * - order: asc|desc
     *
     * @param ListProductsRequest $request Validated request
     * @return JsonResponse
     */
    public function listProducts(ListProductsRequest $request): JsonResponse
    {
        $pagination = $request->getPagination();
        $filters = $request->getFilters();
        $sort = $request->getSort();

        $result = $this->productsService->listProducts(
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            filters: $filters,
            sortBy: $sort['sort'],
            sortOrder: $sort['order'],
        );

        return response()->json($result->toArray());
    }

    /**
     * POST /api/admin/products - Create Product
     *
     * Creates a new product with multilingual names and pricing.
     *
     * @param CreateProductRequest $request Validated request
     * @return JsonResponse
     */
    public function storeProduct(CreateProductRequest $request): JsonResponse
    {
        try {
            $product = $this->productsService->createProduct($request->validated());
            return response()->json($product->toArray(), 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'validation_failed', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * PATCH /api/admin/products/{productId} - Update Product
     *
     * Updates product properties. Price changes apply only to new transactions.
     *
     * @param string $productId
     * @param UpdateProductRequest $request Validated request
     * @return JsonResponse
     */
    public function updateProduct(string $productId, UpdateProductRequest $request): JsonResponse
    {
        try {
            $product = $this->productsService->updateProduct($productId, $request->validated());
            return response()->json($product->toArray());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/admin/products/{productId}/status - Toggle Product Status
     *
     * Activates or deactivates a product.
     *
     * @param string $productId
     * @return JsonResponse
     */
    public function toggleProductStatus(string $productId): JsonResponse
    {
        try {
            $isActive = request()->boolean('is_active', true);
            $product = $this->productsService->toggleStatus($productId, $isActive);
            return response()->json($product->toArray());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }
    }
}
