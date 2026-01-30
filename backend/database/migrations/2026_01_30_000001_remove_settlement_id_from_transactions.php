<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove settlement_id column from transactions table
 *
 * Settlement status is now determined by presence in settlement_items table.
 * This aligns with ADR-0004: settlement_items is the source of truth.
 *
 * Migration Steps:
 * 1. Drop foreign key constraint
 * 2. Drop settlement_id column
 *
 * Rollback:
 * - Re-adds column and FK
 * - Populates settlement_id from settlement_items (data recovery)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['settlement_id']);

            // Drop column
            $table->dropColumn('settlement_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Re-add column
            $table->uuid('settlement_id')->nullable()->index();

            // Re-add foreign key
            $table->foreign('settlement_id')
                ->references('id')
                ->on('settlements')
                ->onDelete('set null');
        });

        // Recover settlement_id from settlement_items (data consistency)
        \DB::statement('
            UPDATE transactions t
            JOIN settlement_items si ON t.id = si.transaction_id
            JOIN settlements s ON si.settlement_id = s.id
            SET t.settlement_id = si.settlement_id
            WHERE s.is_cancelled = false
        ');
    }
};
