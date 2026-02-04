<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CategoriesService;
use App\Service\ProductsService;
use App\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductsAdminController
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

        return $this->json($response, $categories);
    }

    public function storeCategory(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $category = $this->categoriesService->createCategory($body, $adminId);

        return $this->json($response, $category->toArray(), 201);
    }

    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        $category = $this->categoriesService->updateCategory($categoryId, $body, $adminId);

        return $this->json($response, $category->toArray());
    }

    public function toggleCategoryStatus(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'is_active' => ['required', 'boolean'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $category = $this->categoriesService->toggleStatus($categoryId, (bool) $body['is_active'], $adminId);

        return $this->json($response, $category->toArray());
    }

    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $args['categoryId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->categoriesService->deleteCategory($categoryId, $adminId);

        return $this->json($response, ['message' => 'Category deleted']);
    }

    public function reorderCategories(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'category_ids' => ['required', 'array'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $this->categoriesService->reorderCategories($body['category_ids'], $adminId);

        return $this->json($response, ['message' => 'Categories reordered']);
    }

    // --- Products ---

    public function listProducts(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortOrder = $params['sort_order'] ?? 'desc';

        $filters = [];
        if (isset($params['category_id'])) {
            $filters['category_id'] = $params['category_id'];
        }
        if (isset($params['is_active'])) {
            $filters['is_active'] = $params['is_active'];
        }

        $result = $this->productsService->listProducts($limit, $offset, $filters, $sortBy, $sortOrder);

        return $this->json($response, $result->toArray());
    }

    public function storeProduct(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

        if (!$this->validator->validate($body, [
            'names' => ['required', 'array'],
            'category_id' => ['required', 'uuid'],
            'price_cents' => ['required', 'integer'],
        ])) {
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $product = $this->productsService->createProduct($body, $adminId);

        return $this->json($response, $product->toArray(), 201);
    }

    public function updateProduct(Request $request, Response $response, array $args): Response
    {
        $productId = $args['productId'];
        $body = $request->getParsedBody() ?? [];
        $adminId = $request->getAttribute('admin_user_id');

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
            return $this->json($response, ['error' => 'Validation failed', 'details' => $this->validator->errors()], 422);
        }

        $product = $this->productsService->toggleStatus($productId, (bool) $body['is_active'], $adminId);

        return $this->json($response, $product->toArray());
    }

    public function deleteProduct(Request $request, Response $response, array $args): Response
    {
        $productId = $args['productId'];
        $adminId = $request->getAttribute('admin_user_id');

        $this->productsService->deleteProduct($productId, $adminId);

        return $this->json($response, ['message' => 'Product deleted']);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
