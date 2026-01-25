<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create audit_log table for compliance and accountability
 *
 * Per ADR-0013 (Audit Logging for Master Data Changes):
 * - Append-only audit trail for all master data changes
 * - Records who made changes (admin_user_id), what changed (old/new values),
 *   when (created_at), and from where (ip_address, user_agent)
 * - Sensitive fields (IBAN, passwords) are masked before logging
 * - Retained for 10 years per § 147 AO (German tax code)
 *
 * Database Schema from erm-master.md:
 * - id: BIGINT auto-increment (unique identifier)
 * - admin_user_id: BINARY(16) foreign key (nullable for system/failed actions)
 * - action: ENUM (create, update, delete, anonymize, login, logout, login_failed, export, settlement_create, settlement_cancel, settlement_export)
 * - entity_type: VARCHAR(50) (member, product, admin_user, terminal, settlement, sepa_config)
 * - entity_id: VARCHAR(36) (UUID of affected record)
 * - old_values: JSON (field values before change, null for create)
 * - new_values: JSON (field values after change, null for delete)
 * - ip_address: VARCHAR(45) (IPv4/IPv6 address of request)
 * - user_agent: VARCHAR(500) (browser/client identifier)
 * - created_at: DATETIME (timestamp of action)
 *
 * Indexes for common queries:
 * - admin_user_id: Filter by admin who performed action
 * - action: Filter by action type (create, update, delete, etc.)
 * - entity_type, entity_id: Find all changes to specific entity
 * - created_at: Order by timestamp, date range queries
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            // Primary key: Auto-increment ID
            $table->bigIncrements('id');

            // Foreign key: Admin who performed action (nullable for system/failed actions)
            $table->string('admin_user_id', 36)->nullable()->index();

            // Action type: create, update, delete, anonymize, login, logout, etc.
            $table->enum('action', [
                'create',
                'update',
                'delete',
                'anonymize',
                'login',
                'logout',
                'login_failed',
                'export',
                'settlement_create',
                'settlement_cancel',
                'settlement_export',
            ])->index();

            // Entity type: member, product, admin_user, terminal, settlement, sepa_config
            $table->string('entity_type', 50)->index();

            // Entity ID: Primary key of affected record (UUID format)
            $table->string('entity_id', 36)->index();

            // Change capture: Before/after values in JSON
            $table->json('old_values')->nullable();  // null for create action
            $table->json('new_values')->nullable();  // null for delete action

            // Request context: IP address and user agent for security analysis
            $table->string('ip_address', 45)->nullable();    // Supports IPv6
            $table->string('user_agent', 500)->nullable();

            // Immutable timestamp (no updated_at - append-only)
            $table->dateTime('created_at')->useCurrent()->index();

            // Composite index: Find all changes to a specific entity
            $table->index(['entity_type', 'entity_id']);

            // Composite index: Timeline queries (by date + action type)
            $table->index(['created_at', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
