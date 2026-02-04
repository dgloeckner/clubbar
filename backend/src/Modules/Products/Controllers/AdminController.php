<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private CategoriesService $categoriesService,
        private ProductsService $productsService,
        private Validator $validator,
    ) {}

    // --- Categories ---

    public function listCategories(Request $request, Response $response): Response
    {
        $categories = $this->categoriesService->listCategories();

        return $this->json($response, ['categories' => $categories]);
    }

    public function storeCategory(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array'],
            'display_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'icon_name' => ['nullable', 'string', 'max:50'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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
            'names' => ['nullable', 'array'],
            'display_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'icon_name' => ['nullable', 'string', 'max:50'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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

    public function reorderCategories(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'category_ids' => ['required', 'array'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $this->categoriesService->reorderCategories($body['category_ids'], $adminId);

        return $this->json($response, ['message' => 'Categories reordered']);
    }

    // --- Products ---

    public function listProducts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Validate query parameters
        $validationData = [
            'per_page' => $params['per_page'] ?? null,
            'page' => $params['page'] ?? null,
            'status' => $params['status'] ?? null,
            'category_id' => $params['category_id'] ?? null,
            'sort_by' => $params['sort_by'] ?? null,
        ];

        if (!$this->validator->validate($validationData, [
            'per_page' => ['nullable', 'integer', 'gt:0'],
            'page' => ['nullable', 'integer', 'gt:0'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'category_id' => ['nullable', 'uuid'],
            'sort_by' => ['nullable', 'in:name_asc,name_desc,price_asc,price_desc,category'],
        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
        }

        $limit = (int) ($params['per_page'] ?? $params['limit'] ?? 50);
        $limit = min($limit, 100); // Enforce maximum limit
        $offset = (int) ($params['page'] ?? 1);
        $offset = ($offset - 1) * $limit; // Convert page number to offset

        // Parse combined sort_by parameter (e.g., "price_asc" => sortBy="price", sortOrder="asc")
        $sortByParam = $params['sort_by'] ?? 'name_asc';
        [$sortBy, $sortOrder] = $this->parseSortBy($sortByParam);

        $filters = [];
        if (isset($params['category_id'])) {
            $filters['category_id'] = $params['category_id'];
        }
        if (isset($params['status'])) {
            $filters['status'] = $params['status'];
        }

        $result = $this->productsService->listProducts($limit, $offset, $filters, $sortBy, $sortOrder);

        return $this->json($response, $result->toArray());
    }

    private function parseSortBy(string $sortBy): array
    {
        // Map combined sort parameters to [field, direction]
        $map = [
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'price_asc' => ['price', 'asc'],
            'price_desc' => ['price', 'desc'],
            'category' => ['category', 'asc'],
        ];

        return $map[$sortBy] ?? ['created_at', 'desc'];
    }

    public function storeProduct(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array', 'min:1'],
            'category_id' => ['required', 'uuid'],
            'price_cents' => ['required', 'integer', 'gt:0'],
            'icon_name' => ['nullable', 'string', 'max:50'],        ])) {
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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

            if (!empty($rules) && !$this->validator->validate($body, $rules)) {
                return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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
            return $this->json($response, ['error' => 'validation_failed', 'messages' => $this->validator->errors()], 422);
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

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
