<?php

/**
 * Transactions Module Route Aggregator
 *
 * Aggregates all route files for the Transactions module.
 * Pattern 009: Module Structure (ADR-0018)
 *
 * This file is included by routes/api.php in the global route scope.
 * All routes defined here are accessible at /api/sync, /api/terminal, and /api/admin.
 *
 * The order matters:
 * 1. terminal.php - Terminal API routes (Batch upload, transaction history)
 * 2. admin.php - Admin API routes (Manual corrections, CSV exports)
 */

require base_path('app/Http/Modules/Transactions/routes/terminal.php');
require base_path('app/Http/Modules/Transactions/routes/admin.php');
