<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add settlement_id to transactions table
 *
 * Links transaction to settlement it belongs to.
 * NULL = not yet settled (available for new settlement)
 * SET NULL on settlement delete (keep transaction history, remove settlement link)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('settlement_id')->nullable()->index();
            $table->foreign('settlement_id')
                ->references('id')
                ->on('settlements')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeignKeyIfExists('transactions_settlement_id_foreign');
            $table->dropColumn('settlement_id');
        });
    }
};
