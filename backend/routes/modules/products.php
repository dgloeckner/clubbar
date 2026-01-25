<?php

use Illuminate\Support\Facades\Route;

/**
 * Products Module Routes
 *
 * Includes all product and category management endpoints.
 * This file is included by routes/api.php.
 *
 * Includes:
 * - admin.php: Admin API endpoints (category and product CRUD)
 * - terminal.php: Terminal API endpoints (delta sync)
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 */

// Include module-specific route files
require base_path('app/Http/Modules/Products/routes/admin.php');
require base_path('app/Http/Modules/Products/routes/terminal.php');
