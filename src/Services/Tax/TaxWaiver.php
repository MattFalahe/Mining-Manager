<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MiningTax;

/**
 * Let a bill off: the corporation will not collect what is left on it.
 *
 * Mark as Paid was the only way to close a bill nobody was going to pay, and
 * it recorded ISK that never reached the wallet, so Collected ran ahead of
 * what the corporation actually took in. A waived bill keeps what was paid on
 * it as paid, lets the rest off, and records nothing as collected.
 *
 * The bill's payment code stays as it is. A payment quoting it later goes to
 * the member's other open bills and then their balance, as for any bill let
 * off, so what was let off never changes afterwards.
 */
class TaxWaiver
{
    /**
     * @return array{tax: MiningTax, waived: float, paid: float}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \DomainException when the bill has nothing owed on it to let off
     */
    public function waive(int $taxId, string $reason, ?int $userId, string $userName): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($taxId, $reason, $userId, $userName) {
            // Locked like Mark as Paid locks it: a wallet payment landing
            // meanwhile would otherwise be let off with the rest.
            $tax = MiningTax::where('id', $taxId)->lockForUpdate()->firstOrFail();

            if (!in_array($tax->status, MiningTax::OUTSTANDING_STATUSES, true)) {
                throw new \DomainException(trans('mining-manager::taxes.waive_not_owed', [
                    'status' => trans('mining-manager::taxes.' . $tax->status),
                ]));
            }

            $paid = round((float) ($tax->amount_paid ?? 0), 2);
            $waived = $tax->getRemainingBalance();

            $note = Carbon::now()->format('Y-m-d H:i') . " - Waived by {$userName}: "
                . number_format($waived, 0) . ' ISK let off'
                . ($paid > 0 ? ', the ' . number_format($paid, 0) . ' ISK paid before stays paid' : '')
                . ".\nReason: {$reason}";

            $tax->status = 'waived';
            $tax->notes = $tax->notes ? $tax->notes . "\n\n" . $note : $note;

            if (MiningTax::recordsWriteOff()) {
                $tax->written_off_at = Carbon::now();
                $tax->written_off_by = $userId;
                $tax->written_off_reason = $reason;
            }

            $tax->save();

            return ['tax' => $tax, 'waived' => $waived, 'paid' => $paid];
        });
    }
}
