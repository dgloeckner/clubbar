<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create members table for member management
 *
 * Schema from erm-master.md:
 * - id: UUID primary key
 * - card_uid: RFID/NFC identifier (unique, immutable)
 * - names: First and last names (nullable for GDPR)
 * - email: Contact email
 * - preferred_language: ISO 639-1 code
 * - iban: SEPA bank account
 * - mandate_reference: SEPA mandate ID (unique)
 * - mandate_signed_at: Mandate signature date
 * - is_active: Active/blocked flag
 * - deleted_at: Soft-delete timestamp (GDPR)
 * - created_at, updated_at: Timestamps
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            // Primary key: UUID
            $table->uuid('id')->primary();

            // Member identification
            $table->string('card_uid', 20)->unique()->nullable();     // RFID/NFC card UID
            $table->string('first_name', 100)->nullable();            // First name (nullable for GDPR)
            $table->string('last_name', 100)->nullable();             // Last name (nullable for GDPR)

            // Contact information
            $table->string('email', 255)->nullable();                 // Contact email
            $table->string('phone', 20)->nullable();                  // Phone number

            // Preferences
            $table->string('preferred_language', 10)->default('de');  // ISO 639-1 code

            // SEPA payment information
            $table->string('iban', 34)->nullable();                   // SEPA bank account
            $table->string('mandate_reference', 35)->unique()->nullable(); // SEPA mandate ID
            $table->date('mandate_signed_at')->nullable();            // Mandate signature date

            // Status and tracking
            $table->boolean('is_active')->default(true);             // Active/blocked flag
            $table->softDeletes();                                    // deleted_at timestamp for GDPR
            $table->timestamps();                                     // created_at, updated_at

            // Indexes for query performance
            $table->index('is_active');                               // For active member queries
            $table->index('created_at');                              // For list ordering
            $table->index('updated_at');                              // For delta sync
            $table->index(['is_active', 'created_at']);              // Composite for filtering + ordering
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
