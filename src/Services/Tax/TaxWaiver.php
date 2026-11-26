<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MiningManager\Models\MiningTax;

/**
 * Let a bill off: the corporation will not collect it.
 *
 * Mark as Paid was the only way to close a bill nobody was going to pay, and
 * it recorded ISK that never reached the wallet, so Collected ran ahead of
 * what the corporation actually took in. A waived bill records nothing as
 * collected, and whatever was paid on it moves to the member's balance, where
 * the next wallet check pays their oldest open bill with it. A payment made
 * before the waive so ends up where one made after it does.
 *
 * The bill's payment code stays as it is. A payment quoting it later goes to
 * the member's other open bills and then their balance, as for any bill let
 * off, so what was let off never changes afterwards.
 */
class TaxWaiver
{
    /**
     * @return array{tax: MiningTax, waived: float, moved: float, stays: float}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \DomainException when the bill has nothing owed on it to let off
     */
    public function waive(int $taxId, string $reason, ?int $userId, string $userName, ?int $actorId = null): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($taxId, $reason, $userId, $userName, $actorId) {
            // Locked like Mark as Paid locks it: a wallet payment landing
            // meanwhile would otherwise be let off with the rest.
            $tax = MiningTax::where('id', $taxId)->lockForUpdate()->firstOrFail();

            if (!in_array($tax->status, MiningTax::OUTSTANDING_STATUSES, true)) {
                throw new \DomainException(trans('mining-manager::taxes.waive_not_owed', [
                    'status' => trans('mining-manager::taxes.' . $tax->status),
                ]));
            }

            $paid = round((float) ($tax->amount_paid ?? 0), 2);
            $moved = $paid > 0
                ? app(PaymentAllocationService::class)->holdPaymentsOfLetOffBill(
                    $tax,
                    $actorId ?? $userId,
                    "Taken off bill #{$tax->id} when {$userName} waived it"
                )
                : 0.0;
            $stays = $tax->takeOffPaid($moved);
            $waived = $tax->getRemainingBalance();

            $note = Carbon::now()->format('Y-m-d H:i') . " - Waived by {$userName}: "
                . number_format($waived, 0) . ' ISK let off.'
                . ($moved > 0 ? ' ' . number_format($moved, 0) . " ISK paid on it moved to the member's balance." : '')
                . ($stays > 0 ? ' ' . number_format($stays, 0) . ' ISK credited before payments were recorded one by one stays on it.' : '')
                . "\nReason: {$reason}";

            $tax->status = 'waived';
            $tax->notes = $tax->notes ? $tax->notes . "\n\n" . $note : $note;

            if (MiningTax::recordsWriteOff()) {
                $tax->written_off_at = Carbon::now();
                $tax->written_off_by = $userId;
                $tax->written_off_reason = $reason;
            }

            $tax->save();

            return ['tax' => $tax, 'waived' => $waived, 'moved' => $moved, 'stays' => $stays];
        });
    }
}
