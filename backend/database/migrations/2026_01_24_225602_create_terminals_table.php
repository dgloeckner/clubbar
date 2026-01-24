<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create terminals table for terminal device authentication
 *
 * Pattern 012: Terminal API Token Authentication
 * Stores terminal metadata and bcrypt-hashed API tokens for device-level auth.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Terminal metadata
            $table->string('name');                          // Display name (e.g., "Bar Counter 1")
            $table->string('device_id')->unique();          // Unique device identifier
            $table->string('api_token_hash')->nullable();   // Bcrypt hash of API token

            // Status and tracking
            $table->boolean('is_active')->default(true);    // Soft-disable terminals
            $table->timestamp('last_sync_at')->nullable();  // Track terminal activity

            // Timestamps
            $table->timestamps();

            // Indexes for query performance
            $table->index('is_active');  // For active terminal queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
