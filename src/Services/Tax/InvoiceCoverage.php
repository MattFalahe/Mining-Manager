<?php

namespace MiningManager\Services\Tax;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Is a ledger row already accounted for on an invoice somebody has been sent?
 *
 * Several places need to know this and they must all agree, so the rule lives
 * here once rather than being re-expressed as a slightly different query each
 * time. An invoice counts as issued when a payment code has been generated for
 * it, money has arrived against it, or its status already says paid or partial
 * -- the same three conditions TaxCalculationService::invoiceFreezeReason()
 * applies at the invoice level.
 *
 * A row that is covered is evidence of what somebody was billed. It does not
 * get deleted, shrunk, re-rated or reclassified, whatever else the pipeline
 * would normally do to it.
 */
class InvoiceCoverage
{
    /**
     * characterId => list of [start, end] date strings for issued invoices.
     * Loaded once per character; process-ledger walks many rows per character,
     * so this keeps the row-level check off the database.
     */
    private static array $ranges = [];

    /**
     * Whether an issued invoice covers this character's mining on this date.
     *
     * @param mixed $date
     */
    public static function coversRow(int $characterId, $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        try {
            $day = Carbon::parse($date)->toDateString();
        } catch (\Throwable $e) {
            // An unreadable date is not something to make deletion decisions on.
            return true;
        }

        foreach (self::rangesFor($characterId) as [$start, $end]) {
            if ($day >= $start && $day <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exclude covered rows from a mining_ledger query.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     */
    public static function excludeFrom($query, string $table = 'mining_ledger'): void
    {
        $query->whereRaw("NOT EXISTS (
            SELECT 1
            FROM mining_taxes t
            LEFT JOIN mining_tax_codes c
                   ON c.mining_tax_id = t.id
            WHERE t.character_id = {$table}.character_id
              AND {$table}.date >= COALESCE(t.period_start, t.month)
              AND {$table}.date <= COALESCE(
                    t.period_end,
                    LAST_DAY(COALESCE(t.period_start, t.month))
                  )
              AND (t.status IN (?, ?) OR t.amount_paid > 0 OR c.id IS NOT NULL)
        )", ['paid', 'partial']);
    }

    /**
     * Issued-invoice periods for one character, loaded once.
     *
     * period_start/period_end are the modern columns; older records carry only
     * `month`, so fall back to that month's span.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private static function rangesFor(int $characterId): array
    {
        if (array_key_exists($characterId, self::$ranges)) {
            return self::$ranges[$characterId];
        }

        $ranges = [];

        try {
            $rows = DB::table('mining_taxes as t')
                ->leftJoin('mining_tax_codes as c', 'c.mining_tax_id', '=', 't.id')
                ->where('t.character_id', $characterId)
                ->where(function ($q) {
                    $q->whereIn('t.status', ['paid', 'partial'])
                      ->orWhere('t.amount_paid', '>', 0)
                      ->orWhereNotNull('c.id');
                })
                ->select('t.period_start', 't.period_end', 't.month')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $start = $row->period_start ?? $row->month;

                if (! $start) {
                    continue;
                }

                $start = Carbon::parse($start);
                $end = $row->period_end
                    ? Carbon::parse($row->period_end)
                    : $start->copy()->endOfMonth();

                $ranges[] = [$start->toDateString(), $end->toDateString()];
            }
        } catch (\Throwable $e) {
            // If we cannot tell, assume covered. Skipping a cleanup is
            // recoverable; deleting a row behind a paid invoice is not.
            Log::warning('Mining Manager: could not read invoice coverage, treating mining as covered', [
                'character_id' => $characterId,
                'error' => $e->getMessage(),
            ]);

            return self::$ranges[$characterId] = [['0000-01-01', '9999-12-31']];
        }

        return self::$ranges[$characterId] = $ranges;
    }

    /**
     * Drop the memo. Long-running commands should call this between runs so a
     * newly generated invoice is seen.
     */
    public static function flush(): void
    {
        self::$ranges = [];
    }
}
