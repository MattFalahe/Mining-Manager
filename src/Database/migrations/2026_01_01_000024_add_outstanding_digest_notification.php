<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-webhook opt-in for the outstanding tax digest.
 *
 * Every other tax notification speaks to one member about one debt. Nothing
 * gave a director the whole picture, so an invoice that slipped through the
 * per-member notifications simply went unnoticed: no Discord on that member,
 * a delivery that failed quietly, or a gap in the matching we have not found
 * yet. The digest is the backstop that makes any of those visible.
 *
 * Additive and defaulted false, so no existing webhook starts emitting a new
 * type on upgrade. No backfill.
 */
class AddOutstandingDigestNotification extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webhook_configurations')
            && !Schema::hasColumn('webhook_configurations', 'notify_tax_outstanding_digest')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->boolean('notify_tax_outstanding_digest')->default(false)->after('notify_schedule_mismatch');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_configurations')
            && Schema::hasColumn('webhook_configurations', 'notify_tax_outstanding_digest')) {
            Schema::table('webhook_configurations', function (Blueprint $table) {
                $table->dropColumn('notify_tax_outstanding_digest');
            });
        }
    }
}
