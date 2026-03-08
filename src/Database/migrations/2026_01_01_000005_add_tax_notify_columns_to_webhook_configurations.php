<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTaxNotifyColumnsToWebhookConfigurations extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->boolean('notify_tax_reminder')->default(false)->after('notify_event_completed');
            $table->boolean('notify_tax_invoice')->default(false)->after('notify_tax_reminder');
            $table->boolean('notify_tax_overdue')->default(false)->after('notify_tax_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_configurations', function (Blueprint $table) {
            $table->dropColumn(['notify_tax_reminder', 'notify_tax_invoice', 'notify_tax_overdue']);
        });
    }
}
