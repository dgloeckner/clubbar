<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create settlements table for periodic billing (SEPA + manual)
 *
 * Implements UC-A30: Create Settlement
 * Implements ADR-0007: SEPA Configuration and ADR-0009: Settlement Lead Times
 *
 * Columns:
 * - id: UUID primary key
 * - settlement_type: ENUM(sepa, manual) - billing method
 * - settlement_date: Date when settlement was created
 * - execution_date: Date when payment will be executed (>= settlement_date + 7 days for SEPA)
 * - period_start, period_end: Date range of transactions included
 * - sepa_message_id: Unique identifier for SEPA XML exports (format: SET-YYYY-NNN)
 * - manual_reason: Reason for manual settlement (cash, bank_transfer, write_off, other)
 * - total_amount_cents: Sum of all items in settlement (for quick access)
 * - member_count: Number of unique members in settlement (for UI)
 * - is_cancelled: Whether settlement was cancelled before export
 * - cancelled_at: Timestamp of cancellation
 * - cancelled_by_admin_id: Admin who cancelled
 * - exported_at: When settlement was exported/finalized
 * - notes: Admin notes on settlement
 * - created_by_admin_id: Admin who created settlement
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Settlement classification
            $table->enum('settlement_type', ['sepa', 'manual']); // Type of settlement
            $table->enum('manual_reason', ['cash', 'bank_transfer', 'write_off', 'other'])->nullable(); // Manual reason (required if type=manual)

            // Date information
            $table->date('settlement_date');        // Date settlement created
            $table->date('execution_date');         // When payment executed (>= settlement_date + 7 for SEPA)
            $table->date('period_start')->nullable(); // Start of transaction period
            $table->date('period_end')->nullable();   // End of transaction period

            // SEPA-specific fields
            $table->string('sepa_message_id', 35)->nullable()->unique(); // Unique SEPA message ID (SET-YYYY-NNN)

            // Settlement totals (denormalized for UI performance)
            $table->unsignedBigInteger('total_amount_cents')->default(0); // Sum of items
            $table->unsignedInteger('member_count')->default(0);          // Unique members

            // Cancellation tracking
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by_admin_id')->nullable();

            // Export tracking
            $table->timestamp('exported_at')->nullable(); // When settlement finalized

            // Metadata
            $table->string('notes', 1000)->nullable();

            // Foreign keys
            $table->uuid('created_by_admin_id')->index(); // Admin who created
            $table->foreign('created_by_admin_id')
                ->references('id')
                ->on('admin_users')
                ->onDelete('restrict');

            $table->foreign('cancelled_by_admin_id')
                ->references('id')
                ->on('admin_users')
                ->onDelete('restrict')
                ->onUpdate('cascade');

            // Timestamps
            $table->timestamps();

            // Indexes for query performance
            $table->index('settlement_type');       // Filter by type
            $table->index('settlement_date');       // Filter by date
            $table->index('execution_date');        // Find upcoming executions
            $table->index('is_cancelled');          // Find active settlements
            $table->index(['settlement_type', 'is_cancelled']); // Common filter combination
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
