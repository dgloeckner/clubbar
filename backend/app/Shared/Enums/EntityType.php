<?php

namespace App\Shared\Enums;

/**
 * EntityType Enum - Type-Safe Audit Entity Types
 *
 * Per Pattern 002: Enum for Type-Safe Domain Values
 * Per ADR-0013: Defines all entity types that can be audited
 *
 * Entity Types:
 * - MEMBER: Member records (names, contact, SEPA, preferences)
 * - CATEGORY: Product categories (organization, ordering)
 * - PRODUCT: Product catalog (names, prices, descriptions)
 * - ADMIN_USER: Administrator accounts (emails, roles, permissions)
 * - TERMINAL: POS terminals (device config, API tokens, assignments)
 * - SETTLEMENT: Financial settlements (creation, cancellation, export)
 * - SEPA_CONFIG: SEPA/banking configuration (creditor info, formats)
 */
enum EntityType: string
{
    case MEMBER = 'member';
    case CATEGORY = 'category';
    case PRODUCT = 'product';
    case ADMIN_USER = 'admin_user';
    case TERMINAL = 'terminal';
    case SETTLEMENT = 'settlement';
    case SEPA_CONFIG = 'sepa_config';
}
