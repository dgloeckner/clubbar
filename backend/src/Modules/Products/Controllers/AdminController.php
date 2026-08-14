<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Validation\Validator;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private CategoriesService $categoriesService,
        private ProductsService $productsService,
        private Validator $validator,
    ) {}

    // --- Categories ---

    public function listCategories(Request $request, Response $response): Response
    {
        $categories = $this->categoriesService->listCategories();

        // Categories are few and always returned whole, but the key is `data`
        // like every other list — a fifth spelling for a fifth endpoint is how
        // the frontend ended up with a fallback chain per screen (#119).
        return $this->json($response, ['data' => $categories]);
    }

    public function storeCategory(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'icon_name' => ['nullable', 'string', 'max:50'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $category = $this->categoriesService->createCategory($body, $adminId);

        return $this->json($response, $category->toArray(), 201);
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['nullable', 'array', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'icon_name' => ['nullable', 'string', 'max:50'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        try {
            $category = $this->categoriesService->updateCategory($categoryId, $body, $adminId);
            return $this->json($response, $category->toArray());
        } catch (NotFoundException $e) {
            return $this->json($response, [
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage()
            ], $e->getHttpStatusCode());
        }
    }

    public function toggleCategoryStatus(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'is_active' => ['required', 'boolean'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        try {
            $category = $this->categoriesService->toggleStatus($categoryId, (bool) $body['is_active'], $adminId);
            return $this->json($response, $category->toArray());
        } catch (NotFoundException $e) {
            return $this->json($response, [
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage()
            ], $e->getHttpStatusCode());
        }
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->categoriesService->deleteCategory($categoryId, $adminId);

        return $response->withStatus(204);
    }

    // --- Products ---

    public function listProducts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Pagination and sorting are ListQuery's business; what is left here
        // is the filter vocabulary, which is genuinely per-endpoint.
        if (!$this->validator->validate([
            'status' => $params['status'] ?? null,
            'category_id' => $params['category_id'] ?? null,
            'sort_by' => $params['sort_by'] ?? null,
            'search' => $params['search'] ?? null,
        ], [
            'status' => ['nullable', 'in:all,active,inactive'],
            'category_id' => ['nullable', 'uuid'],
            'sort_by' => ['nullable', 'in:name_asc,name_desc,price_asc,price_desc,category_asc,category_desc,created_at_asc,created_at_desc'],
            'search' => ['nullable', 'string'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $query = ListQuery::fromParams($params);

        $filters = [];
        if (isset($params['category_id'])) {
            $filters['category_id'] = $params['category_id'];
        }
        if (isset($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if ($query->search !== null) {
            $filters['search'] = $query->search;
        }

        $result = $this->productsService->listProducts(
            $query->perPage,
            $query->offset,
            $filters,
            $query->sortKey,
            $query->sortOrder,
        );

        return $this->json($response, PaginatedResponse::fromQuery($result->items, $result->total, $query));
    }

    public function storeProduct(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array', 'min:1'],
            'category_id' => ['required', 'uuid'],
            'price_cents' => ['required', 'integer', 'gt:0'],
            'icon_name' => ['nullable', 'string', 'max:50'],
            'requires_dispenser' => ['nullable', 'boolean'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $product = $this->productsService->createProduct($body, $adminId);

        return $this->json($response, $product->toArray(), 201);
    }

    public function updateProduct(Request $request, Response $response, array $args): Response
    {
        $productId = $args['productId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!empty($body)) {
            $rules = [];
            if (isset($body['names'])) $rules['names'] = ['array', 'min:1'];
            if (isset($body['category_id'])) $rules['category_id'] = ['uuid'];
            if (isset($body['price_cents'])) $rules['price_cents'] = ['integer', 'gt:0'];
            if (isset($body['icon_name'])) $rules['icon_name'] = ['nullable', 'string', 'max:50'];
            if (isset($body['requires_dispenser'])) $rules['requires_dispenser'] = ['boolean'];

            if (!empty($rules) && !$this->validator->validate($body, $rules)) {
                return $this->validationFailed($response, $this->validator->errors());
            }
        }

        $product = $this->productsService->updateProduct($productId, $body, $adminId);

        return $this->json($response, $product->toArray());
    }

    public function toggleProductStatus(Request $request, Response $response, array $args): Response
    {
        $productId = $args['productId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'is_active' => ['required', 'boolean'],
        ])) {
            return $this->validationFailed($response, $this->validator->errors());
        }

        $product = $this->productsService->toggleStatus($productId, (bool) $body['is_active'], $adminId);

        return $this->json($response, $product->toArray());
    }

    public function deleteProduct(Request $request, Response $response, array $args): Response
    {
        $productId = $args['productId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->productsService->deleteProduct($productId, $adminId);

        return $response->withStatus(204);
    }
}
