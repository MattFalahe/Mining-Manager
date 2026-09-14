<?php

namespace MiningManager\Services\Ledger;

/**
 * Mining that reaches the ledger after the invoice covering it has gone out.
 *
 * It is recorded, because it happened, but not taxed, because the invoice it
 * belongs to is already a record. Both importers write it, so the wording and
 * the arithmetic live here once.
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
}
