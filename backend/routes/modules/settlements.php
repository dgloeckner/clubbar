<?php

/**
 * Settlements Module Route Aggregator
 *
 * Aggregates all route files for the Settlements module.
 * Pattern 009: Module Structure (ADR-0018)
 *
 * This file is included by routes/api.php in the global route scope.
 * All routes defined here are accessible at /api/admin.
 */

require base_path('app/Http/Modules/Settlements/routes/admin.php');
