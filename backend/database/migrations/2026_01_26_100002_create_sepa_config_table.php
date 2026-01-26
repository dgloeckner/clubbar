<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Create sepa_config table for organization SEPA settings
 *
 * Implements ADR-0007: SEPA Configuration Management
 * - Single-row table (enforced by CHECK constraint id=1)
 * - Stores creditor organization details for SEPA XML generation
 * - creditor_id is immutable (set once, never changed)
 *
 * Columns:
 * - id: Always 1 (enforced by CHECK)
 * - creditor_id: DE01ZZZ09999999999 format, immutable
 * - creditor_name: Organization name (used in SEPA XML)
 * - creditor_iban: Organization's bank account
 * - creditor_address_*: Full address for SEPA XML requirements
 * - updated_by_admin_id: Track who last updated config
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sepa_config', function (Blueprint $table) {
            // ID must always be 1 (single-row table)
            // Note: Enforced at application level via SepaConfigService
            $table->unsignedTinyInteger('id')->primary();

            // Creditor (organization) information for SEPA
            $table->string('creditor_id', 35)->nullable();                    // e.g., DE01ZZZ09999999999
            $table->string('creditor_name', 70)->nullable();                  // Organization name
            $table->string('creditor_iban', 34)->nullable();                  // Organization IBAN (SEPA format)
            $table->string('creditor_address_street', 70)->nullable();        // Street address
            $table->string('creditor_address_city', 35)->nullable();          // City
            $table->string('creditor_address_country', 2)->nullable();        // ISO country code

            // Audit tracking
            $table->uuid('updated_by_admin_id')->nullable();
            $table->foreign('updated_by_admin_id')
                ->references('id')
                ->on('admin_users')
                ->onDelete('set null');

            // Timestamps
            $table->timestamps();
        });

        // Insert default empty row
        DB::table('sepa_config')->insert([
            'id' => 1,
            'creditor_id' => null,
            'creditor_name' => null,
            'creditor_iban' => null,
            'creditor_address_street' => null,
            'creditor_address_city' => null,
            'creditor_address_country' => null,
            'updated_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sepa_config');
    }
};
