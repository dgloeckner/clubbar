<?php

/**
 * Dashboard Module Route Aggregator
 *
 * Includes all routes from the Dashboard module.
 * This file is loaded by routes/api.php to register module routes.
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 */

require base_path('app/Http/Modules/Dashboard/routes/admin.php');
