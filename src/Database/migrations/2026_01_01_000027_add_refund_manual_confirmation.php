<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room to record a refund confirmed by a person rather than by the wallet.
 *
 * A refund normally confirms itself: the reconciler sees the ISK leave the
 * corporation wallet carrying the agreed keyword and attaches the transaction.
 * That covers the ordinary case and leaves nothing to remember.
 *
 * It does not cover every case. A director who forgets the keyword, or who
 * sends the ISK by contract, or who pays from a wallet the plugin cannot read,
 * produces a transfer nothing will ever match. The refund then sits pending for
 * good, and the outstanding figure on the balances page slowly stops meaning
 * anything, which is worse than the problem the figure was there to solve.
 *
 * So a director can say the money went out. That claim is weaker than a matched
 * transaction and the two must never look alike afterwards, hence recording who
 * made it and why. A refund with a transaction_id was proved by the wallet; one
 * without it was somebody's word, and now the page can say so.
 */
class AddRefundManualConfirmation extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_payment_refunds')) {
            return;
        }

        Schema::table('mining_manager_payment_refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('mining_manager_payment_refunds', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')->nullable()->after('refunded_by');
            }

            if (!Schema::hasColumn('mining_manager_payment_refunds', 'confirmation_note')) {
                // Why it was confirmed by hand. Required at the point of doing
                // it, because "confirmed, no transaction, no explanation" is
                // exactly the row nobody can audit six months later.
                $table->string('confirmation_note', 255)->nullable()->after('confirmed_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mining_manager_payment_refunds')) {
            return;
        }

        Schema::table('mining_manager_payment_refunds', function (Blueprint $table) {
            foreach (['confirmation_note', 'confirmed_by'] as $column) {
                if (Schema::hasColumn('mining_manager_payment_refunds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
