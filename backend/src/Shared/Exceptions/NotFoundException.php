<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a requested resource is not found
 */
class NotFoundException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 404;
    }

    public function getErrorCode(): string
    {
        return 'not_found';
    }

    /**
     * Create exception for a specific resource type
     */
    public static function forResource(string $resourceType, string $id): self
    {
        return new self("{$resourceType} not found: {$id}");
    }
}
