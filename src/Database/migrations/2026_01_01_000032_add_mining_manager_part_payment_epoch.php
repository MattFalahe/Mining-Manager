<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Stamp the part-payment cutover.
 *
 * Theft detection has only ever treated unpaid and overdue bills as unpaid. A
 * part-paid bill keeps status Partial however late it gets, so any payment at
 * all took it off that list: a guest could pay a token sum on a bill and have
 * their theft incident closed as paid.
 *
 * From here on a part-paid bill counts as unpaid for theft detection. Applying
 * that to bills already raised would open incidents, all at once, over debts
 * nobody was chasing under the old rule, so it starts with bills raised after
 * this timestamp. Bills raised before it keep the old rule.
 *
 * Nothing existing is modified. The migration only writes one settings row.
 * Not corporation-scoped: the cutover is a property of the code version, and
 * every corporation on this install upgrades at the same moment.
 */
class AddMiningManagerPartPaymentEpoch extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        // Never move a cutover that is already set: moving it forward would hand
        // bills back to the old rule. The unique index on (key, corporation_id)
        // cannot stop a duplicate here, because MySQL treats NULLs in a unique
        // index as distinct from one another.
        $already = DB::table('mining_manager_settings')
            ->where('key', 'tax.part_payment_epoch')
            ->whereNull('corporation_id')
            ->exists();

        if ($already) {
            return;
        }

        $now = Carbon::now();

        DB::table('mining_manager_settings')->insertOrIgnore([
            'key' => 'tax.part_payment_epoch',
            'value' => $now->toDateTimeString(),
            'type' => 'string',
            'corporation_id' => null,
            'description' => 'Part-payment cutover. Part-paid bills raised after this count as unpaid for theft detection.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        DB::table('mining_manager_settings')
            ->where('key', 'tax.part_payment_epoch')
            ->whereNull('corporation_id')
            ->delete();
    }
}
