<?php

namespace MiningManager\Services\Ledger;

/**
 * Mining that reaches the ledger after the invoice covering it has gone out.
 *
 * It is recorded, because it happened, but not taxed, because the invoice it
 * belongs to is already a record. Both importers write it and the character
 * ledger reads it back, so the wording and the arithmetic live here once.
 */
final class LateMining
{
    /** A whole row that turned up after its period was invoiced. */
    public const NEW_ROW_NOTE = 'Arrived after this period was invoiced, so it was not taxed.';

    /** A row that was on the bill and has grown since. */
    public const GROWTH_NOTE = 'Some of this arrived after this period was invoiced, and that part was not taxed.';

    /**
     * What to write to a billed row when more mining turns up for it.
     *
     * The billed part keeps the value and the tax it was billed at, so nothing
     * about what the member was charged moves. Only the extra is added, at
     * today's value. The tax columns are left out of the result on purpose, so
     * they stay exactly as billed. The note is added once, and never replaces a
     * note already on the row.
     *
     * @param  object  $existing  The ledger row as it stands.
     * @param  int  $quantity  The row's new total quantity.
     * @param  array  $extraValues  OreValuationService::calculateOreValue() for the extra quantity alone.
     * @return array  Attributes to update.
     */
    public static function growth(object $existing, int $quantity, array $extraValues): array
    {
        $totalValue = (float) $existing->total_value + (float) ($extraValues['total_value'] ?? 0);

        $notes = trim((string) ($existing->notes ?? ''));

        if ($notes === '') {
            $notes = self::GROWTH_NOTE;
        } elseif (strpos($notes, self::NEW_ROW_NOTE) === false && strpos($notes, self::GROWTH_NOTE) === false) {
            $notes .= ' ' . self::GROWTH_NOTE;
        }

        return [
            'quantity' => $quantity,
            'ore_value' => (float) $existing->ore_value + (float) ($extraValues['ore_value'] ?? 0),
            'mineral_value' => (float) $existing->mineral_value + (float) ($extraValues['mineral_value'] ?? 0),
            'total_value' => $totalValue,
            'unit_price' => $quantity > 0 ? $totalValue / $quantity : (float) $existing->unit_price,
            'notes' => $notes,
        ];
    }

    /**
     * A billed day's row figures, with what came after the bill taken out.
     *
     * A row that arrived late is not taxed, unless it would not have been taxed
     * on time either, which is the better reason to show. A row that grew keeps
     * only the tax its billed part was charged, which is whatever the day's
     * other rows leave of the day's tax. Its rate stays, because the billed part
     * was charged at it, and billed_share says how much of the row that part
     * is, so the page can explain a tax smaller than the rate gives and list
     * the rest as not taxed. A day with nothing late comes back as it was.
     *
     * @param  iterable<object>  $rows  The day's ledger rows, each with id and notes.
     * @param  array<int, array{taxed: bool, rate: float, tax: float, discount: float, reason: string|null}>  $figures  Row id => figures, as worked out from the day's breakdown.
     * @param  float  $billed  The tax the day was billed at.
     * @return array<int, array>  The figures with the day's rows corrected. A row cut back to its bill also has billed_share, from 0 to under 1.
     */
    public static function billedDay(iterable $rows, array $figures, float $billed): array
    {
        $grown = [];
        $others = 0.0;

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $notes = (string) ($row->notes ?? '');

            if (str_contains($notes, self::NEW_ROW_NOTE)) {
                if ($figures[$id]['taxed'] || $figures[$id]['reason'] === 'not_counted') {
                    $figures[$id] = ['taxed' => false, 'rate' => 0.0, 'tax' => 0.0, 'discount' => 0.0, 'reason' => 'after_bill'] + $figures[$id];
                }
            } elseif ($figures[$id]['taxed'] && str_contains($notes, self::GROWTH_NOTE)) {
                $grown[] = $id;
            } else {
                $others += $figures[$id]['tax'];
            }
        }

        $grownTax = array_sum(array_map(fn (int $id) => $figures[$id]['tax'], $grown));
        if ($grownTax <= 0) {
            return $figures;
        }

        $kept = max(0.0, min($grownTax, $billed - $others)) / $grownTax;
        if ($kept >= 1) {
            return $figures;
        }

        foreach ($grown as $id) {
            $figures[$id]['tax'] = round($figures[$id]['tax'] * $kept, 2);
            $figures[$id]['discount'] = round($figures[$id]['discount'] * $kept, 2);
            $figures[$id]['billed_share'] = $kept;
        }

        return $figures;
    }
}
