<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add icon_name column to products table
 *
 * Stores the name of the icon to display for this product.
 * Format: string (e.g., "PilsIcon", "WeizenIcon")
 * Nullable: Yes (uses default PackageIcon if not set)
 * Indexed: Yes (for efficient icon-based filtering if needed)
 *
 * Implements ADR-0031: Icon Selection for Products and Categories
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('icon_name', 50)->nullable()->after('descriptions');
            $table->index('icon_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['icon_name']);
            $table->dropColumn('icon_name');
        });
    }
};
