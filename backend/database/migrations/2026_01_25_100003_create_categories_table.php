<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create categories table for product categorization
 *
 * Schema from erm-master.md:
 * - id: UUID primary key
 * - names: Multilingual category names (JSON)
 * - display_order: Terminal tab order (unique)
 * - is_active: Visible on terminal flag
 * - created_at, updated_at: Timestamps
 *
 * Implements ADR-0002: Product Internationalization
 * - Category names stored as JSON with language keys
 * - Terminal selects appropriate language based on member preference
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Multilingual data (JSON)
            // Format: {"de": "Category Name", "en": "Category Name", ...}
            $table->json('names');

            // Display order for terminal (unique constraint)
            $table->integer('display_order')->unique();

            // Status and tracking
            $table->boolean('is_active')->default(true);  // Visible on terminal
            $table->timestamps();                          // created_at, updated_at

            // Indexes for query performance
            $table->index('is_active');             // For active category queries
            $table->index('display_order');         // For ordering
            $table->index('created_at');            // For list ordering
            $table->index('updated_at');            // For delta sync
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
