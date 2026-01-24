<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * GET /sync/members - Delta sync members
     *
     * Returns members modified since `since` timestamp.
     */
    public function members(Request $request): JsonResponse
    {
        $since = $request->query('since', '1970-01-01T00:00:00Z');

        // Mock member data matching OAS schema
        $members = [
            [
                'id' => '123e4567-e89b-12d3-a456-426614174000',
                'card_uid' => '04:d2:3e:5a:10:80:80',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'preferred_language' => 'de',
                'is_active' => true,
                'is_sepa_valid' => true,
                'deleted_at' => null,
                'created_at' => '2024-06-15T10:00:00Z',
                'updated_at' => '2025-01-20T14:23:45Z',
            ],
            [
                'id' => '223e4567-e89b-12d3-a456-426614174001',
                'card_uid' => '04:d2:3e:5a:10:80:90',
                'first_name' => 'Anna',
                'last_name' => 'Schmidt',
                'preferred_language' => 'en',
                'is_active' => true,
                'is_sepa_valid' => true,
                'deleted_at' => null,
                'created_at' => '2024-07-01T12:30:00Z',
                'updated_at' => '2025-01-21T10:00:00Z',
            ],
        ];

        return response()->json([
            'members' => $members,
            'cursor' => '2025-01-21T10:00:00Z',
            'count' => count($members),
            'has_more' => false,
        ]);
    }

    /**
     * GET /sync/categories - Delta sync categories
     *
     * Returns categories modified since `since` timestamp.
     */
    public function categories(Request $request): JsonResponse
    {
        $since = $request->query('since', '1970-01-01T00:00:00Z');

        // Mock category data matching OAS schema
        $categories = [
            [
                'id' => 'abc12345-e21a-11d3-b456-426614174001',
                'names' => [
                    'en' => 'Beverages',
                    'de' => 'Getränke',
                    'fr' => 'Boissons',
                ],
                'display_order' => 1,
                'is_active' => true,
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2025-01-10T10:15:30Z',
            ],
            [
                'id' => 'abc12345-e21a-11d3-b456-426614174002',
                'names' => [
                    'en' => 'Snacks',
                    'de' => 'Snacks',
                    'fr' => 'Encas',
                ],
                'display_order' => 2,
                'is_active' => true,
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2025-01-10T10:15:30Z',
            ],
        ];

        return response()->json([
            'categories' => $categories,
            'cursor' => '2025-01-10T10:15:30Z',
            'count' => count($categories),
            'has_more' => false,
        ]);
    }

    /**
     * GET /sync/products - Delta sync products
     *
     * Returns products modified since `since` timestamp.
     */
    public function products(Request $request): JsonResponse
    {
        $since = $request->query('since', '1970-01-01T00:00:00Z');

        // Mock product data matching OAS schema
        $products = [
            [
                'id' => '987f6543-e21a-11d3-b456-426614174999',
                'names' => [
                    'en' => 'Pilsner 0.5L',
                    'de' => 'Pils 0,5L',
                    'fr' => 'Pilsner 0,5L',
                ],
                'descriptions' => [
                    'en' => 'German pilsner beer',
                    'de' => 'Deutsches Lagerbier',
                    'fr' => 'Bière lager allemande',
                ],
                'price_cents' => 350,
                'category_id' => 'abc12345-e21a-11d3-b456-426614174001',
                'is_active' => true,
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2025-01-10T10:15:30Z',
            ],
            [
                'id' => '987f6543-e21a-11d3-b456-426614174998',
                'names' => [
                    'en' => 'Radler 0.5L',
                    'de' => 'Radler 0,5L',
                    'fr' => 'Radler 0,5L',
                ],
                'descriptions' => [
                    'en' => 'Beer and lemonade mix',
                    'de' => 'Biergemisch',
                    'fr' => 'Mélange de bière et limonade',
                ],
                'price_cents' => 300,
                'category_id' => 'abc12345-e21a-11d3-b456-426614174001',
                'is_active' => true,
                'created_at' => '2024-01-01T00:00:00Z',
                'updated_at' => '2025-01-10T10:15:30Z',
            ],
        ];

        return response()->json([
            'products' => $products,
            'cursor' => '2025-01-10T10:15:30Z',
            'count' => count($products),
            'has_more' => false,
        ]);
    }

    /**
     * PATCH /sync/members/{memberId}/language - Update member language preference
     */
    public function updateLanguage(Request $request, string $memberId): JsonResponse
    {
        $preferredLanguage = $request->input('preferred_language');

        // Validate language code format
        if (!$preferredLanguage || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $preferredLanguage)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'Invalid language code format',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Validate supported languages (mock - in production would check config)
        $supportedLanguages = ['de', 'en', 'fr'];
        if (!in_array($preferredLanguage, $supportedLanguages)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => "Language '{$preferredLanguage}' is not enabled for this organization",
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $memberId)) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Member not found',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 404);
        }

        // Mock success response
        return response()->json([
            'id' => $memberId,
            'preferred_language' => $preferredLanguage,
            'updated_at' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * POST /sync/transactions - Batch upload transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = $request->input('transactions', []);

        // Validate transactions array exists and is not empty
        if (!is_array($transactions) || empty($transactions)) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'Request body must contain a non-empty transactions array',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Validate batch size
        if (count($transactions) > 100) {
            return response()->json([
                'error' => 'invalid_request',
                'message' => 'Maximum 100 transactions per batch',
                'timestamp' => now()->toIso8601ZuluString(),
            ], 400);
        }

        // Validate each transaction (basic validation for mock)
        $acceptedIds = [];
        $errors = [];

        foreach ($transactions as $index => $transaction) {
            $requiredFields = ['id', 'member_id', 'product_id', 'amount_cents', 'created_at'];
            $missingFields = [];

            foreach ($requiredFields as $field) {
                if (!isset($transaction[$field])) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                return response()->json([
                    'error' => 'validation_failed',
                    'message' => 'Validation errors in transaction batch',
                    'timestamp' => now()->toIso8601ZuluString(),
                    'details' => [
                        [
                            'index' => $index,
                            'field' => $missingFields[0],
                            'message' => "Missing required field: {$missingFields[0]}",
                        ],
                    ],
                ], 422);
            }

            // Validate amount_cents is positive
            if ($transaction['amount_cents'] <= 0) {
                return response()->json([
                    'error' => 'validation_failed',
                    'message' => 'Validation errors in transaction batch',
                    'timestamp' => now()->toIso8601ZuluString(),
                    'details' => [
                        [
                            'index' => $index,
                            'field' => 'amount_cents',
                            'message' => 'Amount must be positive',
                        ],
                    ],
                ], 422);
            }

            $acceptedIds[] = $transaction['id'];
        }

        // Mock success response - all transactions accepted
        return response()->json([
            'accepted_ids' => $acceptedIds,
            'rejected' => [
                'count' => 0,
                'errors' => [],
            ],
        ]);
    }
}
