<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds of held account balance.
 *
 * A member can end up holding more balance than they will ever use: they meant
 * to pay 5b and sent 50b, or they are leaving the corporation and the corp
 * should not keep money that is theirs. Until now the only way to deal with
 * that was to send the ISK back in game and hope somebody remembered to write
 * it down, which left the plugin insisting they still had a balance.
 *
 * Nothing here moves ISK. There is no ESI endpoint that can, so the transfer is
 * always a person doing it in game. What this records is the decision and the
 * outcome: the balance comes down the moment a refund is recorded, so the books
 * are right immediately, and the row stays `pending` until an outgoing transfer
 * to that character for that amount turns up in the corporation wallet, at
 * which point it is marked `confirmed` and the transaction is attached.
 *
 * That split matters. Recording only a note lets a refund be agreed and never
 * paid, with nothing to notice. Requiring the transfer first means the balance
 * stays spendable while somebody remembers to come back. Recording now and
 * confirming later gets the balance right straight away and turns "recorded but
 * not paid" into a list rather than an accident.
 */
class CreatePaymentRefunds extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mining_manager_payment_refunds')) {
            return;
        }

        Schema::create('mining_manager_payment_refunds', function (Blueprint $table) {
            $table->bigIncrements('id');

            // The balance this came out of. Kept even after the credit is fully
            // drawn down, so a refund can always be traced to its source.
            $table->unsignedBigInteger('credit_id');
            $table->unsignedBigInteger('character_id');

            $table->decimal('amount', 20, 2);
            $table->string('reason', 500);

            // pending until the money is seen leaving, confirmed once it is.
            $table->string('status', 20)->default('pending');

            // The outgoing wallet entry, once one has been matched to this.
            $table->unsignedBigInteger('transaction_id')->nullable();

            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['character_id', 'status'], 'idx_mm_refund_char_status');
            $table->index('credit_id', 'idx_mm_refund_credit');
            $table->index('status', 'idx_mm_refund_status');

            // One outgoing transfer settles one refund. Without this, a
            // reconciler run that overlapped itself could confirm the same
            // refund twice against the same payment.
            $table->unique('transaction_id', 'uniq_mm_refund_transaction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_payment_refunds');
    }
}
