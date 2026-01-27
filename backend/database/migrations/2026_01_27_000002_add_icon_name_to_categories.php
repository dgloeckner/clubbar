<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add icon_name column to categories table
 *
 * Stores the name of the icon to display for this category.
 * Format: string (e.g., "CategoryIcon", "CategoryTagsIcon")
 * Nullable: Yes (uses default CategoryIcon if not set)
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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon_name', 50)->nullable()->after('names');
            $table->index('icon_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['icon_name']);
            $table->dropColumn('icon_name');
        });
    }
};
