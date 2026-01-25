<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create transactions table for immutable transaction storage
 *
 * Schema from erm-master.md:
 * - id: UUID primary key (frontend-generated)
 * - member_id: Reference to members (required)
 * - product_id: Reference to products (nullable)
 * - amount_cents: Transaction amount in cents
 * - transaction_type: purchase or correction (enum)
 * - notes: Description/reason for transaction
 * - related_transaction_id: Original transaction (for corrections)
 * - created_by_terminal_id: Recording terminal (nullable)
 * - created_by_admin_id: Admin user (for manual entries)
 * - created_at: Transaction timestamp (immutable)
 *
 * Implements ADR-0004: Immutable Transaction Storage
 * - All transactions append-only
 * - Never updated or deleted
 * - Corrections via reverse transactions
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            // Primary key: UUID (frontend-generated)
            $table->uuid('id')->primary();

            // Foreign keys
            $table->uuid('member_id')->index();           // Required: member making transaction
            $table->uuid('product_id')->nullable()->index(); // Optional: product purchased
            $table->uuid('created_by_terminal_id')->nullable(); // Optional: which terminal recorded
            $table->uuid('created_by_admin_id')->nullable();   // Optional: admin entry

            // Transaction data
            $table->integer('amount_cents');              // Cents (positive=charge, negative=correction)
            $table->enum('transaction_type', ['purchase', 'correction'])->default('purchase'); // Type
            $table->string('notes', 500)->nullable();     // Description/reason

            // For corrections: reference to original transaction
            $table->uuid('related_transaction_id')->nullable();

            // Timestamp (immutable - no updated_at)
            $table->timestamp('created_at')->useCurrent(); // Transaction timestamp
            $table->index(['member_id', 'created_at']);   // For balance calculation and history queries

            // Foreign key constraints (soft deletes handled separately)
            $table->foreign('member_id')
                ->references('id')
                ->on('members')
                ->onDelete('restrict'); // Prevent member deletion with transactions

            // Note: product_id foreign key not enforced in production to allow
            // transactions for deleted products (historical data integrity)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
