<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-webhook opt-in for price provider trouble.
 *
 * A price provider that stops answering is the quietest failure the plugin
 * has. Nothing crashes: prices simply stop refreshing, and because a failed
 * lookup no longer overwrites a good price, the numbers stay plausible while
 * they age. With Janice it can also be deliberate, since the owner blocks keys
 * for excessive traffic, and the only sign is a refusal in a log nobody reads.
 *
 * This alert says so once when the refreshes start failing and once when they
 * work again, rather than on every attempt.
 *
 * Additive and defaulted false, so no existing webhook starts emitting a new
 * type on upgrade. No backfill.
 */
class AddPriceProviderNotification extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_configurations')
            && !Schema::hasColumn('webhook_configurations', 'notify_price_provider')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_price_provider')->default(false)->after('notify_tax_outstanding_digest');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_configurations')
            && Schema::hasColumn('webhook_configurations', 'notify_price_provider')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_price_provider');
            });
        }
    }
}
