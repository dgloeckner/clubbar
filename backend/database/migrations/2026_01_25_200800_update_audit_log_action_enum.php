<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            // Modify the action column to include new enum values
            $table->enum('action', [
                'create', 'update', 'delete',
                'activate', 'deactivate', 'reorder',
                'anonymize',
                'login', 'logout', 'login_failed',
                'export',
                'settlement_create', 'settlement_cancel', 'settlement_export'
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->enum('action', [
                'create', 'update', 'delete',
                'anonymize',
                'login', 'logout', 'login_failed',
                'export',
                'settlement_create', 'settlement_cancel', 'settlement_export'
            ])->change();
        });
    }
};
