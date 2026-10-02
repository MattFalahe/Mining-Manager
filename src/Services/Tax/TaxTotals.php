<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use MiningManager\Models\MiningTax;
use MiningManager\Models\PaymentAllocation;

/**
 * The money figures the tax pages put in their summary cards.
 *
 * Every card used to ask its own question of mining_taxes by status, and every
 * one of them left part-paid bills out: "owed" was the bill total of unpaid
 * bills only, "collected" was fully paid bills only. A member who had paid
 * anything on a bill vanished from both, which is how Tax Overview could show
 * nothing owed for somebody with three quarters of a billion outstanding.
 */
class TaxTotals
{
    /**
     * What a set of bills still has to pay, split into late and not yet late.
     *
     * Sums what is left on each bill rather than what it was for. A part-paid
     * bill keeps status Partial however late it gets, so it is counted as late
     * once isOverdue() says so: the same rule as the red badge Tax Overview
     * shows beside it and the overdue wording the reminders use.
     *
     * @param \Illuminate\Database\Eloquent\Builder $bills already scoped to whoever is looking
     * @return array{owed: float, owed_count: int, overdue: float, overdue_count: int}
     */
    public static function outstanding($bills): array
    {
        $totals = ['owed' => 0.0, 'owed_count' => 0, 'overdue' => 0.0, 'overdue_count' => 0];

        $open = (clone $bills)->outstanding()
            ->get(['id', 'status', 'amount_owed', 'amount_paid', 'due_date', 'period_end', 'month']);

        foreach ($open as $bill) {
            $late = $bill->status === 'overdue'
                || ($bill->status === 'partial' && $bill->isOverdue());

            $key = $late ? 'overdue' : 'owed';
            $totals[$key] += $bill->getRemainingBalance();
            $totals[$key . '_count']++;
        }

        $totals['owed'] = round($totals['owed'], 2);
        $totals['overdue'] = round($totals['overdue'], 2);

        return $totals;
    }

    /**
     * Money credited to a set of bills during this calendar month.
     *
     * Read from the allocation ledger, which records every payment on its own
     * line with the day it landed, so a bill paid in instalments counts each
     * instalment in the month it arrived. The bill row only remembers its most
     * recent payment, and its status says nothing until the last one.
     *
     * Every way money reaches a bill writes an allocation since the payment
     * cutover: wallet matches, cascades, account balance, Mark Paid and a
     * status change to Paid.
     *
     * @param \Illuminate\Database\Eloquent\Builder $bills already scoped to whoever is looking
     * @return array{amount: float, bills: int}
     */
    public static function collectedThisMonth($bills): array
    {
        $allocations = PaymentAllocation::whereIn('mining_tax_id', (clone $bills)->select('id'))
            ->whereBetween('allocated_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);

        return [
            'amount' => round((float) (clone $allocations)->sum('amount'), 2),
            'bills' => (int) (clone $allocations)->distinct()->count('mining_tax_id'),
        ];
    }
}
