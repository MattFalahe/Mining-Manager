<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment allocation ledger, surplus credits, and the verification cutover.
 *
 * Until now a wallet payment could only ever settle one invoice, and the only
 * record of "which payment paid this" was mining_taxes.transaction_id — a
 * single column overwritten on every payment. That made two things impossible:
 * splitting one lump sum across several invoices, and reconstructing what a
 * partially-paid invoice was actually made up of.
 *
 *  mining_manager_payment_allocations
 *    One row per (payment, invoice) pair, so a single transfer can be spread
 *    over as many invoices as it covers and every slice keeps its own amount,
 *    origin and author. mining_manager_processed_transactions stays as-is and
 *    keeps its unique claim on transaction_id: the claim answers "has this
 *    payment been consumed", the allocations answer "where did it go".
 *
 *  mining_manager_payment_credits
 *    Surplus left over when a payment is bigger than everything it could be
 *    applied to. Held against the paying character and drawn down when their
 *    next invoice is calculated, rather than silently inflating amount_paid
 *    past amount_owed.
 *
 *  Dedup backfill
 *    mining-manager:verify-payments guarded against re-crediting by checking
 *    mining_taxes.transaction_id. Because that column is overwritten, an
 *    invoice that took two payments inside the lookback window could have the
 *    earlier one credited a second time. The command now guards on
 *    mining_manager_processed_transactions instead — but that table was only
 *    ever written by the other code paths, so every payment the command
 *    credited is missing from it. Without this backfill the first run after
 *    the switch would treat all of them as unclaimed and credit them again.
 *    This copies what we already know (the transaction ids still recorded on
 *    taxes and tax codes) into the claim table. It reads nothing else and
 *    changes no invoice.
 *
 *  payment.dedup_epoch
 *    Cutover stamp. Verification ignores wallet transactions dated before it,
 *    so historical records are left exactly as they stand and are never
 *    re-examined or corrected. Everything from this moment forward is claimed
 *    through the dedup table and can be reconciled. Anything genuinely
 *    unmatched from before the cutover still surfaces on the wallet
 *    verification page, where it can be assigned by hand.
 */
class AddPaymentAllocationsAndDedupEpoch extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mining_manager_payment_allocations')) {
            Schema::create('mining_manager_payment_allocations', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Wallet journal reference id (corporation_wallet_journals.id).
                // Nullable because a credit drawdown allocates money that has
                // no fresh transaction behind it.
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->unsignedBigInteger('credit_id')->nullable();

                $table->unsignedBigInteger('mining_tax_id');
                $table->unsignedBigInteger('character_id')->comment('Paying character');
                $table->decimal('amount', 20, 2);

                // auto | manual | cascade | credit
                $table->string('source', 20)->default('auto');

                $table->unsignedBigInteger('allocated_by')->nullable()->comment('Character ID of who allocated, null for automated');
                $table->text('notes')->nullable();
                $table->timestamp('allocated_at')->useCurrent();
                $table->timestamps();

                $table->index('transaction_id', 'idx_mm_alloc_transaction');
                $table->index('mining_tax_id', 'idx_mm_alloc_tax');
                $table->index('character_id', 'idx_mm_alloc_character');
                $table->index('credit_id', 'idx_mm_alloc_credit');

                // A payment can fund many invoices, but only once each.
                $table->unique(['transaction_id', 'mining_tax_id'], 'uniq_mm_alloc_txn_tax');
            });
        }

        if (!Schema::hasTable('mining_manager_payment_credits')) {
            Schema::create('mining_manager_payment_credits', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('character_id')->comment('Character the credit is held for');
                $table->unsignedBigInteger('transaction_id')->nullable()->comment('Payment that produced the surplus');

                $table->decimal('amount', 20, 2)->comment('Original surplus');
                $table->decimal('remaining', 20, 2)->comment('Not yet drawn down');

                // overpayment | manual
                $table->string('source', 20)->default('overpayment');

                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('character_id', 'idx_mm_credit_character');
                $table->index('transaction_id', 'idx_mm_credit_transaction');
                $table->index(['character_id', 'remaining'], 'idx_mm_credit_char_remaining');
            });
        }

        // One clock for both, so a backfilled claim can never share a second
        // with the cutover. Anything recovered from history is stamped just
        // before it, which keeps the reconciliation check (which only looks at
        // claims from the cutover forward) from reporting them as orphans on
        // day one.
        $now = now();

        $this->backfillProcessedTransactions($now->copy()->subSecond());
        $this->stampDedupEpoch($now);
    }

    public function down(): void
    {
        Schema::dropIfExists('mining_manager_payment_allocations');
        Schema::dropIfExists('mining_manager_payment_credits');

        DB::table('mining_manager_settings')
            ->where('key', 'payment.dedup_epoch')
            ->delete();
    }

    /**
     * Copy known-credited transaction ids into the claim table.
     *
     * Both source columns are strings and have historically held whatever the
     * matching path put there, so anything non-numeric is skipped rather than
     * coerced. insertOrIgnore rather than insert: the claim table's unique
     * constraint on transaction_id is doing the deduplication for us, and the
     * two sources overlap heavily (a fully paid invoice records the same id on
     * both the tax and its code).
     */
    private function backfillProcessedTransactions(Carbon $fallbackMatchedAt): void
    {
        if (!Schema::hasTable('mining_manager_processed_transactions')) {
            return;
        }

        // Only used when the source row has no date of its own. It sits before
        // the cutover so these rows read as history, which is what they are.
        $now = $fallbackMatchedAt;

        if (Schema::hasTable('mining_taxes')) {
            DB::table('mining_taxes')
                ->whereNotNull('transaction_id')
                ->where('transaction_id', '!=', '')
                ->select('id', 'character_id', 'transaction_id', 'paid_at')
                ->orderBy('id')
                ->chunk(500, function ($taxes) use ($now) {
                    $rows = [];

                    foreach ($taxes as $tax) {
                        if (!ctype_digit((string) $tax->transaction_id)) {
                            continue;
                        }

                        $rows[] = [
                            'transaction_id' => (int) $tax->transaction_id,
                            'character_id' => (int) $tax->character_id,
                            'tax_id' => (int) $tax->id,
                            'matched_at' => $tax->paid_at ?: $now,
                            'created_at' => $now,
                        ];
                    }

                    if (!empty($rows)) {
                        DB::table('mining_manager_processed_transactions')->insertOrIgnore($rows);
                    }
                });
        }

        if (Schema::hasTable('mining_tax_codes')) {
            DB::table('mining_tax_codes')
                ->whereNotNull('transaction_id')
                ->where('transaction_id', '!=', '')
                ->select('mining_tax_id', 'character_id', 'transaction_id', 'used_at')
                ->orderBy('id')
                ->chunk(500, function ($codes) use ($now) {
                    $rows = [];

                    foreach ($codes as $code) {
                        if (!ctype_digit((string) $code->transaction_id)) {
                            continue;
                        }

                        $rows[] = [
                            'transaction_id' => (int) $code->transaction_id,
                            'character_id' => (int) $code->character_id,
                            'tax_id' => (int) $code->mining_tax_id,
                            'matched_at' => $code->used_at ?: $now,
                            'created_at' => $now,
                        ];
                    }

                    if (!empty($rows)) {
                        DB::table('mining_manager_processed_transactions')->insertOrIgnore($rows);
                    }
                });
        }
    }

    /**
     * Stamp the cutover, once. insertOrIgnore so a re-run cannot push the
     * epoch forward and silently orphan payments that arrived in between.
     */
    private function stampDedupEpoch(Carbon $now): void
    {
        if (!Schema::hasTable('mining_manager_settings')) {
            return;
        }

        DB::table('mining_manager_settings')->insertOrIgnore([
            'key' => 'payment.dedup_epoch',
            'value' => $now->toDateTimeString(),
            'type' => 'string',
            'corporation_id' => null,
            'description' => 'Wallet verification cutover. Payments dated before this are left as-is and never re-examined.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
