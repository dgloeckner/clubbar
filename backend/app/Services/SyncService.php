<?php

namespace App\Services;

use App\DTOs\CategoryDto;
use App\DTOs\MemberDto;
use App\DTOs\ProductDto;
use App\DTOs\SyncResultDto;
use App\Enums\SupportedLanguage;
use DateTimeImmutable;

/**
 * SyncService
 *
 * Handles sync operations for members, categories, and products.
 * Currently returns mock data for testing. Implements Pattern 004: Service Layer.
 *
 * In production, this would:
 * - Query database for modified records since timestamp
 * - Implement cursor-based pagination
 * - Use repositories for data access
 */
final readonly class SyncService
{
    /**
     * Get members modified since timestamp
     *
     * @param DateTimeImmutable $since Delta sync timestamp
     * @return SyncResultDto
     */
    public function syncMembers(DateTimeImmutable $since): SyncResultDto
    {
        // Mock data for testing
        $members = [
            new MemberDto(
                id: '123e4567-e89b-12d3-a456-426614174000',
                cardUid: '04:d2:3e:5a:10:80:80',
                firstName: 'Max',
                lastName: 'Mustermann',
                preferredLanguage: 'de',
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-06-15T10:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-20T14:23:45Z'),
            ),
            new MemberDto(
                id: '223e4567-e89b-12d3-a456-426614174001',
                cardUid: '04:d2:3e:5a:10:80:90',
                firstName: 'Anna',
                lastName: 'Schmidt',
                preferredLanguage: 'en',
                isActive: true,
                isSepaValid: true,
                deletedAt: null,
                createdAt: new DateTimeImmutable('2024-07-01T12:30:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-21T10:00:00Z'),
            ),
        ];

        return new SyncResultDto(
            items: $members,
            cursor: '2025-01-21T10:00:00Z',
            hasMore: false,
        );
    }

    /**
     * Get categories modified since timestamp
     *
     * @param DateTimeImmutable $since Delta sync timestamp
     * @return SyncResultDto
     */
    public function syncCategories(DateTimeImmutable $since): SyncResultDto
    {
        // Mock data for testing
        $categories = [
            new CategoryDto(
                id: 'abc12345-e21a-11d3-b456-426614174001',
                names: [
                    'en' => 'Beverages',
                    'de' => 'Getränke',
                    'fr' => 'Boissons',
                ],
                displayOrder: 1,
                isActive: true,
                createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-10T10:15:30Z'),
            ),
            new CategoryDto(
                id: 'abc12345-e21a-11d3-b456-426614174002',
                names: [
                    'en' => 'Snacks',
                    'de' => 'Snacks',
                    'fr' => 'Encas',
                ],
                displayOrder: 2,
                isActive: true,
                createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-10T10:15:30Z'),
            ),
        ];

        return new SyncResultDto(
            items: $categories,
            cursor: '2025-01-10T10:15:30Z',
            hasMore: false,
        );
    }

    /**
     * Get products modified since timestamp
     *
     * @param DateTimeImmutable $since Delta sync timestamp
     * @return SyncResultDto
     */
    public function syncProducts(DateTimeImmutable $since): SyncResultDto
    {
        // Mock data for testing
        $products = [
            new ProductDto(
                id: '987f6543-e21a-11d3-b456-426614174999',
                names: [
                    'en' => 'Pilsner 0.5L',
                    'de' => 'Pils 0,5L',
                    'fr' => 'Pilsner 0,5L',
                ],
                descriptions: [
                    'en' => 'German pilsner beer',
                    'de' => 'Deutsches Lagerbier',
                    'fr' => 'Bière lager allemande',
                ],
                priceCents: 350,
                categoryId: 'abc12345-e21a-11d3-b456-426614174001',
                isActive: true,
                createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-10T10:15:30Z'),
            ),
            new ProductDto(
                id: '987f6543-e21a-11d3-b456-426614174998',
                names: [
                    'en' => 'Radler 0.5L',
                    'de' => 'Radler 0,5L',
                    'fr' => 'Radler 0,5L',
                ],
                descriptions: [
                    'en' => 'Beer and lemonade mix',
                    'de' => 'Biergemisch',
                    'fr' => 'Mélange de bière et limonade',
                ],
                priceCents: 300,
                categoryId: 'abc12345-e21a-11d3-b456-426614174001',
                isActive: true,
                createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
                updatedAt: new DateTimeImmutable('2025-01-10T10:15:30Z'),
            ),
        ];

        return new SyncResultDto(
            items: $products,
            cursor: '2025-01-10T10:15:30Z',
            hasMore: false,
        );
    }

    /**
     * Update member language preference
     *
     * @param string $memberId Member ID (UUID)
     * @param SupportedLanguage $language Language to set
     * @return MemberDto
     */
    public function updateMemberLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        // Mock implementation - return updated member
        return new MemberDto(
            id: $memberId,
            cardUid: null,
            firstName: 'Updated',
            lastName: 'Member',
            preferredLanguage: $language->value,
            isActive: true,
            isSepaValid: false,
            deletedAt: null,
            createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
            updatedAt: now(),
        );
    }
}
