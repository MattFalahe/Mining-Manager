<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds soft delete columns to financial tables for audit trail.
     */
    public function up(): void
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mining_taxes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('mining_ledger', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
