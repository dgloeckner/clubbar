<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create admin_users table for admin authentication
 *
 * Pattern 013: Admin Session Authentication
 * Stores admin user credentials and session management data.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Admin credentials
            $table->string('email')->unique();        // Login email (unique)
            $table->string('password_hash');          // bcrypt hashed password
            $table->string('display_name')->nullable(); // Display name in UI

            // Preferences
            $table->string('locale', 10)->default('de'); // UI language preference (ISO 639-1)

            // Status and tracking
            $table->boolean('is_active')->default(true);  // Account enabled/disabled
            $table->timestamp('last_login_at')->nullable(); // Last successful login

            // Timestamps
            $table->timestamps();

            // Indexes for performance
            $table->index('is_active');       // For active user queries
            $table->index('email');          // For login lookup (already unique)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
