<?php

namespace MiningManager\Integrations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MiningReport;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\TaxInvoice;

/**
 * What Mining Manager tells other plugins through Manager Core's event bus,
 * besides a bill being raised.
 *
 * Each event goes out once, when the thing it names happens, and only after
 * the change behind it is saved: one sent from inside a transaction that then
 * rolled back would tell subscribers about something that never happened.
 * Without Manager Core every call does nothing, and a publish that fails never
 * stops the work that caused it.
 */
class ManagerCoreEvents
{
    /**
     * A bill was paid in full, by the wallet, held credit or a director.
     */
    public static function taxPaid(MiningTax $tax): void
    {
        $dueEnd = $tax->effectiveDueDate()->copy()->endOfDay();
        $paidAt = $tax->paid_at ? Carbon::parse($tax->paid_at) : Carbon::now();

        self::publish('mining.tax_paid', self::bill($tax) + [
            // One per bill: two bills of a twice-monthly month share a period.
            'idempotency_key' => "tax_paid:{$tax->id}",
            'paid_at' => $paidAt->toIso8601String(),
            'late' => $paidAt->gt($dueEnd),
        ]);
    }

    /**
     * A bill nothing was paid on went past its due date.
     */
    public static function taxOverdue(MiningTax $tax): void
    {
        self::publish('mining.tax_overdue', self::bill($tax) + [
            'idempotency_key' => "tax_overdue:{$tax->id}",
            'days_overdue' => max(0, -$tax->daysUntilDue()),
        ]);
    }

    /**
     * An invoice for a bill was made and sent to its owner.
     */
    public static function invoiceSent(TaxInvoice $invoice, MiningTax $tax): void
    {
        self::publish('mining.invoice_sent', self::bill($tax) + [
            'invoice_id' => $invoice->id,
            'invoice_amount' => round((float) $invoice->amount, 2),
        ]);
    }

    public static function eventCreated(MiningEvent $event): void
    {
        self::publish('mining.event_created', self::event($event));
    }

    public static function eventStarted(MiningEvent $event): void
    {
        self::publish('mining.event_started', self::event($event));
    }

    public static function eventCompleted(MiningEvent $event): void
    {
        self::publish('mining.event_completed', self::event($event) + [
            'participant_count' => (int) $event->participant_count,
            'total_mined_value' => (int) $event->total_mined_value,
        ]);
    }

    public static function reportGenerated(MiningReport $report): void
    {
        self::publish('mining.report_generated', [
            'report_id' => $report->id,
            'report_type' => $report->report_type,
            'format' => $report->format,
            'start_date' => $report->start_date ? Carbon::parse($report->start_date)->format('Y-m-d') : null,
            'end_date' => $report->end_date ? Carbon::parse($report->end_date)->format('Y-m-d') : null,
            'generated_by' => $report->generated_by,
            'corporation_id' => null,
            'role_id' => null,
        ]);
    }

    /**
     * Jackpot ore found in a chunk's mining, whether the scan found it first
     * or the mining bore out a member's report.
     */
    public static function jackpotDetected(MoonExtraction $extraction): void
    {
        self::publish('mining.jackpot_detected', [
            'extraction_id' => $extraction->id,
            'moon_id' => $extraction->moon_id ?? null,
            'moon_name' => $extraction->moon_name ?? null,
            'structure_id' => $extraction->structure_id ?? null,
            'corporation_id' => $extraction->corporation_id ?? null, // visibility scoping
            'role_id' => null,
            'detected_at' => ($extraction->jackpot_detected_at ? Carbon::parse($extraction->jackpot_detected_at) : Carbon::now())->toIso8601String(),
        ]);
    }

    /**
     * What every bill event carries, the same as mining.tax_created: period
     * is the calendar month the bill's period starts in.
     */
    private static function bill(MiningTax $tax): array
    {
        $start = Carbon::parse($tax->period_start ?? $tax->month);
        $end = $tax->period_end ? Carbon::parse($tax->period_end) : $start->copy()->endOfMonth();

        return [
            'tax_id' => $tax->id,
            'character_id' => (int) $tax->character_id,
            'period' => $start->copy()->startOfMonth()->format('Y-m-01'),
            'period_type' => $tax->period_type,
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'amount_owed' => round((float) $tax->amount_owed, 2),
            'amount_paid' => round((float) ($tax->amount_paid ?? 0), 2),
            'outstanding' => $tax->getRemainingBalance(),
            'status' => $tax->status,
            'due_date' => $tax->effectiveDueDate()->format('Y-m-d'),
            'corporation_id' => null, // visibility scoping (subscriber may filter)
            'role_id' => null,
        ];
    }

    private static function event(MiningEvent $event): array
    {
        return [
            'event_id' => $event->id,
            'name' => $event->name,
            'type' => $event->type,
            'status' => $event->status,
            'start_time' => $event->start_time ? $event->start_time->toIso8601String() : null,
            'end_time' => $event->end_time ? $event->end_time->toIso8601String() : null,
            'tax_modifier' => (int) $event->tax_modifier,
            'corporation_id' => $event->corporation_id, // visibility scoping: null for every corporation
            'role_id' => null,
        ];
    }

    private static function publish(string $topic, array $payload): void
    {
        if (!class_exists(\ManagerCore\Topics::class)) {
            return;
        }

        DB::afterCommit(function () use ($topic, $payload) {
            try {
                \ManagerCore\Topics::publish($topic, $payload);
            } catch (\Throwable $e) {
                Log::warning("Mining Manager: could not publish {$topic} to Manager Core", [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
