<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add foreign key constraint from products to categories
 *
 * Updates:
 * - Makes category_id non-nullable (products must belong to a category)
 * - Adds foreign key constraint to categories table
 * - Prevents deletion of categories with products (RESTRICT)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Change category_id to non-nullable
            $table->uuid('category_id')->nullable(false)->change();

            // Add foreign key constraint
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->onDelete('restrict');  // Prevent category deletion if products exist
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop foreign key
            $table->dropForeign(['category_id']);

            // Revert to nullable
            $table->uuid('category_id')->nullable()->change();
        });
    }
};
