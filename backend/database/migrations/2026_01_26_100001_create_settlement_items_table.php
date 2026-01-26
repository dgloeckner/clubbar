<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create settlement_items table for line items in settlements
 *
 * One row per transaction in a settlement.
 * Denormalized member_id for faster queries without joins.
 *
 * Key constraint: UNIQUE(transaction_id) prevents double-settlement
 * If a transaction appears in two settlements, DB will reject second attempt.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settlement_items', function (Blueprint $table) {
            // Auto-incrementing ID (internal use only)
            $table->id();

            // Foreign keys
            $table->uuid('settlement_id')->index();    // Which settlement
            $table->uuid('transaction_id')->unique();  // Unique constraint: each transaction in one settlement
            $table->uuid('member_id')->index();        // Denormalized for performance

            // Amount in cents (copied from transaction, but stored here for historical accuracy)
            $table->unsignedBigInteger('amount_cents');

            // Foreign key constraints
            $table->foreign('settlement_id')
                ->references('id')
                ->on('settlements')
                ->onDelete('cascade');

            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('restrict'); // Prevent transaction deletion if in settlement

            $table->foreign('member_id')
                ->references('id')
                ->on('members')
                ->onDelete('restrict');

            // Indexes for common queries
            $table->index(['settlement_id', 'member_id']); // Grouped by settlement + member (includes member_id)

            // No timestamps - settlement_items are immutable once created
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_items');
    }
};
