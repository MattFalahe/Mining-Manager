<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_tax_generated')->default(false)->after('notify_tax_overdue');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn('notify_tax_generated');
        });
    }
};
