<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create products table for product catalog
 *
 * Schema from erm-master.md:
 * - id: UUID primary key
 * - category_id: Reference to categories
 * - names: Multilingual product names (JSON)
 * - descriptions: Multilingual descriptions (JSON)
 * - price_cents: Price in cents
 * - is_active: Available for sale flag
 * - created_at, updated_at: Timestamps
 *
 * Implements ADR-0002: Product Internationalization
 * - Product names and descriptions stored as JSON with language keys
 * - Terminal selects appropriate language based on member preference
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Foreign key to categories
            $table->uuid('category_id')->nullable()->index();

            // Multilingual data (JSON)
            // Format: {"de": "Product Name", "en": "Product Name", ...}
            $table->json('names');                  // Product names in multiple languages
            $table->json('descriptions')->nullable(); // Product descriptions in multiple languages

            // Pricing
            $table->integer('price_cents');         // Price in cents (e.g., 350 = €3.50)

            // Status and tracking
            $table->boolean('is_active')->default(true);  // Available for sale
            $table->timestamps();                          // created_at, updated_at

            // Indexes for query performance
            $table->index('is_active');             // For active product queries
            $table->index('created_at');            // For list ordering
            $table->index('updated_at');            // For delta sync
            $table->index(['is_active', 'category_id', 'created_at']); // Composite includes category_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
