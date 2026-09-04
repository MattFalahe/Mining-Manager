<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\PaymentCredit;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Models\PaymentRefund;

/**
 * Giving held balance back.
 *
 * Two jobs. Recording a refund takes the amount off the member's balance
 * immediately, so what they are owed is right from the moment the decision is
 * made. Reconciling then watches the corporation wallet for the ISK actually
 * leaving and marks the refund confirmed when it does.
 *
 * Nothing here sends money. EVE has no API for transferring ISK, so a director
 * makes the transfer in game and this records that it was agreed and, later,
 * that it happened.
 */
class RefundService
{
    private SettingsManagerService $settings;

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * The word a director puts in the transfer reason so this is recognisable.
     *
     * Falls back to the default rather than to nothing: an empty keyword would
     * quietly widen matching to every withdrawal of the right size, which is
     * exactly what the keyword exists to prevent.
     */
    public function keyword(): string
    {
        $keyword = trim((string) ($this->settings->getPaymentSettings()['refund_keyword'] ?? ''));

        return $keyword !== '' ? $keyword : 'MM-REFUND';
    }

    /**
     * A refund and an outgoing transfer are the same event if they agree to
     * within this many ISK. Wallet amounts are exact, so this only absorbs
     * rounding in what somebody typed.
     */
    private const MATCH_TOLERANCE = 1.0;

    /**
     * How far back to look for the outgoing transfer, in days.
     *
     * A director records the refund and sends the ISK, in either order and not
     * always in the same sitting. Wide enough to cover a slow week, narrow
     * enough that an unrelated payout months later cannot be mistaken for this.
     */
    private const MATCH_WINDOW_DAYS = 30;

    /**
     * What a director sending ISK out of the corporation wallet produces.
     *
     * Matching on "negative amount to this character" alone is not tight
     * enough. Market escrow also leaves the wallet towards a character, in
     * thousands of rows, and an escrow that happened to equal a refund would be
     * read as the refund being paid. A person moving money out of a corporation
     * wallet by hand always produces corporation_account_withdrawal, so that is
     * what to look for.
     *
     * A refund paid some other way (a contract, say) simply will not confirm
     * itself, and stays on the pending list for somebody to look at. Visible
     * and unresolved beats automatic and wrong.
     */
    private const OUTGOING_REF_TYPE = 'corporation_account_withdrawal';

    /**
     * Record a refund and take it off the member's balance.
     *
     * @param  int          $creditId  the balance to refund from
     * @param  float|null   $amount    null refunds everything left
     * @return array{success:bool,reason:?string,refund:?PaymentRefund,refunded:float}
     */
    public function record(int $creditId, ?float $amount, string $reason, ?int $actorId = null): array
    {
        $result = ['success' => false, 'reason' => null, 'refund' => null, 'refunded' => 0.0];

        if (trim($reason) === '') {
            $result['reason'] = 'reason_required';

            return $result;
        }

        try {
            $refund = DB::transaction(function () use ($creditId, $amount, $reason, $actorId, &$result) {
                // Lock the row: two directors refunding the same balance at once
                // would otherwise both read the same remaining figure and each
                // take it, handing back twice what is there.
                $credit = PaymentCredit::where('id', $creditId)->lockForUpdate()->first();

                if (!$credit) {
                    $result['reason'] = 'credit_not_found';

                    return null;
                }

                $available = round((float) $credit->remaining, 2);

                if ($available <= 0) {
                    $result['reason'] = 'nothing_left';

                    return null;
                }

                // A null amount means "all of it", which is what somebody
                // leaving the corporation wants and saves them retyping a
                // figure they would only get wrong.
                $take = $amount === null ? $available : round($amount, 2);

                if ($take <= 0) {
                    $result['reason'] = 'amount_not_positive';

                    return null;
                }

                if ($take > $available) {
                    $result['reason'] = 'amount_exceeds_balance';

                    return null;
                }

                $credit->update(['remaining' => round($available - $take, 2)]);

                return PaymentRefund::create([
                    'credit_id' => $credit->id,
                    'character_id' => (int) $credit->character_id,
                    'amount' => $take,
                    'reason' => trim($reason),
                    'status' => PaymentRefund::STATUS_PENDING,
                    'refunded_by' => $actorId,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Mining Manager: could not record a refund', [
                'credit_id' => $creditId,
                'error' => $e->getMessage(),
            ]);

            $result['reason'] = 'error';

            return $result;
        }

        if (!$refund) {
            return $result;
        }

        $result['success'] = true;
        $result['refund'] = $refund;
        $result['refunded'] = (float) $refund->amount;

        Log::info('Mining Manager: refund recorded, awaiting the transfer', [
            'refund_id' => $refund->id,
            'credit_id' => $creditId,
            'character_id' => (int) $refund->character_id,
            'amount' => (float) $refund->amount,
            'refunded_by' => $actorId,
        ]);

        return $result;
    }

    /**
     * Say the money went out, without a transfer to prove it.
     *
     * Needed because the automatic path can only ever see one shape of
     * evidence. A director who forgets the keyword, sends the ISK by contract,
     * or pays out of a wallet the plugin cannot read leaves a refund that no
     * run will ever match, and it would sit pending for good. One stuck row is
     * a nuisance; enough of them and the outstanding total on the balances page
     * means nothing, which defeats the point of tracking refunds at all.
     *
     * No transaction is attached, deliberately. That absence is what marks the
     * row as somebody's word rather than the wallet's, and everything that
     * reads it afterwards can tell the two apart.
     *
     * @return array{success:bool,reason:?string,refund:?PaymentRefund}
     */
    public function markSent(int $refundId, string $note, ?int $actorId = null): array
    {
        $result = ['success' => false, 'reason' => null, 'refund' => null];

        if (trim($note) === '') {
            $result['reason'] = 'note_required';

            return $result;
        }

        try {
            $refund = DB::transaction(function () use ($refundId, $note, $actorId, &$result) {
                // Locked because the reconciler may be partway through matching
                // this very row. Without the lock a director and a cron run can
                // both confirm it, and the later write silently undoes the
                // earlier one, losing either the transaction or the note.
                $refund = PaymentRefund::where('id', $refundId)->lockForUpdate()->first();

                if (!$refund) {
                    $result['reason'] = 'refund_not_found';

                    return null;
                }

                if ($refund->status !== PaymentRefund::STATUS_PENDING) {
                    $result['reason'] = 'refund_not_pending';

                    return null;
                }

                $refund->update([
                    'status' => PaymentRefund::STATUS_CONFIRMED,
                    'confirmed_at' => Carbon::now(),
                    'confirmed_by' => $actorId,
                    'confirmation_note' => trim($note),
                ]);

                return $refund;
            });
        } catch (\Throwable $e) {
            Log::error('Mining Manager: could not mark a refund as sent', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);

            $result['reason'] = 'error';

            return $result;
        }

        if (!$refund) {
            return $result;
        }

        $result['success'] = true;
        $result['refund'] = $refund;

        Log::info('Mining Manager: refund confirmed by hand, no matching transfer', [
            'refund_id' => $refund->id,
            'character_id' => (int) $refund->character_id,
            'amount' => (float) $refund->amount,
            'confirmed_by' => $actorId,
            'note' => trim($note),
        ]);

        return $result;
    }

    /**
     * Undo a hand confirmation and let it wait for a transfer again.
     *
     * Refund rows for the same person look much alike, so confirming the wrong
     * one is an easy mistake to make and, without this, an impossible one to
     * correct outside the database.
     *
     * Only rows confirmed by hand can be reopened. A refund matched to a real
     * transaction is backed by evidence that has not changed, and reopening it
     * would achieve nothing anyway: the next run would find the same transfer
     * and confirm it again.
     *
     * Balances are untouched. They came down when the refund was recorded, and
     * that decision is not what is being corrected here.
     *
     * @return array{success:bool,reason:?string,refund:?PaymentRefund}
     */
    public function revertToPending(int $refundId, ?int $actorId = null): array
    {
        $result = ['success' => false, 'reason' => null, 'refund' => null];

        try {
            $refund = DB::transaction(function () use ($refundId, &$result) {
                $refund = PaymentRefund::where('id', $refundId)->lockForUpdate()->first();

                if (!$refund) {
                    $result['reason'] = 'refund_not_found';

                    return null;
                }

                if ($refund->status !== PaymentRefund::STATUS_CONFIRMED) {
                    $result['reason'] = 'refund_not_confirmed';

                    return null;
                }

                if ($refund->transaction_id !== null) {
                    $result['reason'] = 'refund_matched_to_transfer';

                    return null;
                }

                $refund->update([
                    'status' => PaymentRefund::STATUS_PENDING,
                    'confirmed_at' => null,
                    'confirmed_by' => null,
                    'confirmation_note' => null,
                ]);

                return $refund;
            });
        } catch (\Throwable $e) {
            Log::error('Mining Manager: could not reopen a refund', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);

            $result['reason'] = 'error';

            return $result;
        }

        if (!$refund) {
            return $result;
        }

        $result['success'] = true;
        $result['refund'] = $refund;

        Log::info('Mining Manager: hand confirmation withdrawn, refund pending again', [
            'refund_id' => $refund->id,
            'character_id' => (int) $refund->character_id,
            'amount' => (float) $refund->amount,
            'reopened_by' => $actorId,
        ]);

        return $result;
    }

    /**
     * Look for the ISK leaving, and confirm any refund it settles.
     *
     * Reads every division, not just the one configured for payments. That
     * setting says where tax is meant to arrive; a refund goes out of whichever
     * division actually had the ISK, and requiring it to match would leave
     * perfectly good refunds sitting pending forever.
     *
     * The narrowing that matters is the ref_type rather than the division: a
     * withdrawal made by a person, to this character, for this amount, inside
     * the window. Anything looser lets market escrow in.
     *
     * @return array{confirmed:int,ambiguous:int}
     */
    public function reconcile(?int $corporationId = null): array
    {
        $pending = PaymentRefund::pending()->orderBy('created_at')->get();

        if ($pending->isEmpty()) {
            return ['confirmed' => 0, 'ambiguous' => 0];
        }

        $since = Carbon::now()->subDays(self::MATCH_WINDOW_DAYS);
        $keyword = $this->keyword();
        $confirmed = 0;
        $ambiguous = 0;

        // Transaction ids already spoken for, so two refunds of the same amount
        // to the same person cannot both claim one transfer.
        $claimed = PaymentRefund::confirmed()
            ->whereNotNull('transaction_id')
            ->pluck('transaction_id')
            ->all();

        // Refunds closed by hand cannot appear in that list: they have no
        // transaction, which is the point of them. That leaves a gap. If a
        // director closes one by hand and the transfer that actually paid it
        // turns up afterwards carrying the keyword, nothing marks that transfer
        // as spent, and an identical refund still waiting would take it and be
        // confirmed on money that was never meant for it.
        //
        // So remember what was closed by hand, and refuse to auto-confirm
        // anything that matches one. Character and amount are enough: they are
        // the only things the matcher has to go on, and if those agree there is
        // no way to tell which refund a transfer belongs to.
        $closedByHand = PaymentRefund::confirmed()
            ->whereNull('transaction_id')
            ->where('confirmed_at', '>=', $since)
            ->get(['character_id', 'amount']);

        foreach ($pending as $refund) {
            $query = DB::table('corporation_wallet_journals')
                ->where('ref_type', self::OUTGOING_REF_TYPE)
                ->where('second_party_id', $refund->character_id)
                ->where('date', '>=', $since)
                ->where('amount', '<', 0)
                ->whereRaw('ABS(ABS(amount) - ?) <= ?', [(float) $refund->amount, self::MATCH_TOLERANCE]);

            if ($corporationId) {
                $query->where('corporation_id', $corporationId);
            }

            if (!empty($claimed)) {
                $query->whereNotIn('id', $claimed);
            }

            // The keyword is what separates a refund from an SRP payout, a
            // ship reimbursement, or any other reason a corporation hands a
            // member money. Amount and recipient alone cannot tell those apart,
            // and confirming a refund against somebody's SRP would take a real
            // debt off the books.
            $candidates = $query->orderBy('date')->get()
                ->filter(function ($row) use ($keyword) {
                    $text = trim(($row->reason ?? '') . ' ' . ($row->description ?? ''));

                    return $text !== '' && stripos($text, $keyword) !== false;
                })
                ->values()
                ->take(2);

            if ($candidates->isEmpty()) {
                continue;
            }

            // A sibling refund for the same person and amount was closed by
            // hand, so this transfer may well be the one that paid it. Leave
            // this refund alone rather than confirm it on somebody else's ISK.
            $shadowed = $closedByHand->first(function ($other) use ($refund) {
                return (int) $other->character_id === (int) $refund->character_id
                    && abs((float) $other->amount - (float) $refund->amount) <= self::MATCH_TOLERANCE;
            });

            if ($shadowed) {
                $ambiguous++;
                Log::info('Mining Manager: a matching refund was closed by hand, so this transfer is not being claimed', [
                    'refund_id' => $refund->id,
                    'character_id' => (int) $refund->character_id,
                    'amount' => (float) $refund->amount,
                ]);

                continue;
            }

            // Two transfers that both fit means guessing, and guessing here
            // marks money as returned that might not have been. Leave it for
            // somebody who can tell them apart.
            if ($candidates->count() > 1) {
                $ambiguous++;
                Log::info('Mining Manager: more than one transfer fits this refund, leaving it pending', [
                    'refund_id' => $refund->id,
                    'character_id' => (int) $refund->character_id,
                    'amount' => (float) $refund->amount,
                    // The notes are what lets somebody tell them apart.
                    'candidates' => $candidates->map(fn ($c) => [
                        'transaction_id' => (int) $c->id,
                        'date' => (string) $c->date,
                        'note' => $c->reason ?: ($c->description ?? ''),
                    ])->all(),
                ]);

                continue;
            }

            $match = $candidates->first();

            $refund->update([
                'status' => PaymentRefund::STATUS_CONFIRMED,
                'transaction_id' => (int) $match->id,
                'confirmed_at' => Carbon::now(),
            ]);

            $claimed[] = (int) $match->id;
            $confirmed++;

            Log::info('Mining Manager: refund confirmed against an outgoing transfer', [
                'refund_id' => $refund->id,
                'transaction_id' => (int) $match->id,
                'character_id' => (int) $refund->character_id,
                'amount' => (float) $refund->amount,
            ]);
        }

        return ['confirmed' => $confirmed, 'ambiguous' => $ambiguous];
    }
}
