<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ConvertEventsToTaxModifier extends Migration
{
    /**
     * Run the migrations.
     *
     * Converts the event system from ISK bonus payouts to tax rate modifiers.
     * - bonus_percentage (0-100) becomes tax_modifier (-100 to +100)
     * - Negative = tax discount, Positive = tax increase
     * - Example: -100 = tax-free, +100 = double tax
     */
    public function up(): void
    {
        // Step 1: Add new columns to mining_events
        Schema::table('mining_events', function (Blueprint $table) {
            // Tax modifier: -100 (tax-free) to +100 (double tax)
            $table->integer('tax_modifier')->default(0)->after('total_mined');
            // Corporation ID for corp-scoped events
            $table->unsignedBigInteger('corporation_id')->nullable()->after('tax_modifier');

            // Add index for corporation filtering
            $table->index('corporation_id');
        });

        // Step 2: Migrate existing bonus_percentage data to tax_modifier
        // Convert positive bonus (ISK payout) to negative modifier (tax discount)
        // This preserves the intent: "bonus event" = "reduced tax event"
        if (Schema::hasColumn('mining_events', 'bonus_percentage')) {
            DB::statement('UPDATE mining_events SET tax_modifier = -CAST(bonus_percentage AS SIGNED) WHERE bonus_percentage > 0');
        }

        // Step 3: Drop old bonus_percentage column
        if (Schema::hasColumn('mining_events', 'bonus_percentage')) {
            Schema::table('mining_events', function (Blueprint $table) {
                $table->dropColumn('bonus_percentage');
            });
        }

        // Step 4: Remove bonus_earned from event_participants
        if (Schema::hasColumn('event_participants', 'bonus_earned')) {
            Schema::table('event_participants', function (Blueprint $table) {
                $table->dropColumn('bonus_earned');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore bonus_earned to event_participants
        Schema::table('event_participants', function (Blueprint $table) {
            $table->decimal('bonus_earned', 20, 2)->default(0)->after('quantity_mined');
        });

        // Restore bonus_percentage to mining_events
        Schema::table('mining_events', function (Blueprint $table) {
            $table->decimal('bonus_percentage', 5, 2)->default(0)->after('total_mined');
        });

        // Migrate data back: negative tax_modifier becomes positive bonus_percentage
        DB::statement('UPDATE mining_events SET bonus_percentage = -tax_modifier WHERE tax_modifier < 0');

        // Drop new columns
        Schema::table('mining_events', function (Blueprint $table) {
            $table->dropIndex(['corporation_id']);
            $table->dropColumn(['tax_modifier', 'corporation_id']);
        });
    }
}
