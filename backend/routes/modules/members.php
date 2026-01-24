<?php

/**
 * Members Module Route Aggregator
 *
 * Aggregates all route files for the Members module.
 * Pattern 009: Module Structure (ADR-0018)
 *
 * This file is included by routes/api.php in the global route scope.
 * All routes defined here are accessible at /api/sync, /api/admin, and /api/auth.
 *
 * The order matters:
 * 1. auth.php - Authentication routes (login, logout, profile)
 * 2. terminal.php - Terminal API routes (Member sync endpoints)
 * 3. admin.php - Admin API routes (Member management endpoints)
 */

require base_path('app/Http/Modules/Members/routes/auth.php');
require base_path('app/Http/Modules/Members/routes/terminal.php');
require base_path('app/Http/Modules/Members/routes/admin.php');
