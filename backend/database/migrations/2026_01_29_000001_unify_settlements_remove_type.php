<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unify settlements - remove settlement_type distinction
 *
 * Removes settlement_type column as settlements are now unified.
 * Export format (SEPA XML, CSV) is determined at export time, not at creation.
 *
 * Keeps manual_reason for audit trail and context.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            // Drop the settlement_type column and related index
            $table->dropIndex('settlements_settlement_type_index');
            $table->dropIndex('settlements_settlement_type_is_cancelled_index');
            $table->dropColumn('settlement_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            // Restore settlement_type column
            $table->enum('settlement_type', ['sepa', 'manual'])->after('id');
            $table->index('settlement_type');
            $table->index(['settlement_type', 'is_cancelled']);
        });
    }
};
