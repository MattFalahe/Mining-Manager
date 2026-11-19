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
use MiningManager\Models\TheftIncident;

/**
 * What Mining Manager tells other plugins through Manager Core's event bus.
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
     * Bills raised whose mining.tax_created has not gone out yet, by id.
     *
     * @var array<int, bool>
     */
    private static array $raised = [];

    /**
     * A bill was raised. It goes out once the bill is committed, saying what
     * held credit has paid of it by then.
     */
    public static function taxCreated(MiningTax $tax): void
    {
        if (!self::carries('mining.tax_created')) {
            return;
        }

        self::$raised[$tax->id] = true;

        DB::afterCommit(function () use ($tax) {
            self::announceRaised($tax);
        });
    }

    /**
     * A bill was paid in full, by the wallet, held credit or a director.
     */
    public static function taxPaid(MiningTax $tax): void
    {
        if (!self::carries('mining.tax_paid')) {
            return;
        }

        $dueEnd = $tax->effectiveDueDate()->copy()->endOfDay();
        $paidAt = $tax->paid_at ? Carbon::parse($tax->paid_at) : Carbon::now();

        $payload = self::bill($tax) + [
            // One per bill: two bills of a twice-monthly month share a period.
            // And one per time it is paid, since a bill can reopen and be
            // paid again, which a key of the bill alone would drop.
            'idempotency_key' => "tax_paid:{$tax->id}:" . self::when($tax),
            'paid_at' => $paidAt->toIso8601String(),
            'late' => $paidAt->gt($dueEnd),
        ];

        DB::afterCommit(function () use ($tax, $payload) {
            // Held credit can pay a bill off in the transaction that raises
            // it, and Laravel runs what an inner transaction queued before
            // what the outer one did. Raised has to come before paid.
            self::announceRaised($tax);
            self::send('mining.tax_paid', $payload);
        });
    }

    /**
     * A bill went past its due date with money still owed: one nothing was
     * paid on turns overdue, and one part paid stays partial, which part_paid
     * says.
     */
    public static function taxOverdue(MiningTax $tax): void
    {
        self::publish('mining.tax_overdue', self::bill($tax) + [
            'idempotency_key' => "tax_overdue:{$tax->id}:" . self::when($tax),
            'days_overdue' => max(0, -$tax->daysUntilDue()),
            'part_paid' => (float) ($tax->amount_paid ?? 0) > 0,
        ]);
    }

    /**
     * A director let a bill off, waived or exempted. It counts as never
     * billed from then on, so a plugin following the bill can stop chasing it.
     */
    public static function taxWrittenOff(MiningTax $tax): void
    {
        self::publish('mining.tax_written_off', array_merge(self::bill($tax), [
            'idempotency_key' => "tax_written_off:{$tax->id}:" . self::when($tax),
            // Nothing is left to pay on a bill let off, whatever was paid of it.
            'outstanding' => 0.0,
        ]));
    }

    /**
     * A bill that was paid or let off is open again: a director set it back
     * to Unpaid or Overdue, a payment on it was undone, or what it owes went
     * up. A plugin that heard it was settled should chase it again.
     */
    public static function taxReopened(MiningTax $tax, string $previousStatus): void
    {
        self::publish('mining.tax_reopened', self::bill($tax) + [
            'idempotency_key' => "tax_reopened:{$tax->id}:" . self::when($tax),
            'previous_status' => $previousStatus,
            'reopened_at' => Carbon::now()->toIso8601String(),
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
     * A theft incident was closed: resolved or called a false alarm by a
     * director, or taken off the list once nothing was overdue any more.
     * Without it, a plugin that heard about the theft never hears it ended.
     */
    public static function theftResolved(TheftIncident $incident): void
    {
        $resolvedAt = $incident->resolved_at ? Carbon::parse($incident->resolved_at) : Carbon::now();

        self::publish('mining.theft_resolved', [
            'incident_id' => $incident->id,
            'character_id' => (int) $incident->character_id,
            'status' => $incident->status,
            'resolved_at' => $resolvedAt->toIso8601String(),
            'severity' => $incident->severity,
            'tax_owed' => (float) $incident->tax_owed,
            'ore_value' => (float) $incident->ore_value,
            'corporation_id' => null, // visibility scoping, as mining.theft_detected
            'role_id' => null,
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

    /**
     * When a bill's status changed, for keys that are one per change rather
     * than one per bill.
     */
    private static function when(MiningTax $tax): int
    {
        return $tax->updated_at ? Carbon::parse($tax->updated_at)->getTimestamp() : Carbon::now()->getTimestamp();
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

    /**
     * Send mining.tax_created for a bill, once. The bill is read again here
     * because held credit is applied after it is raised, and subscribers are
     * told what is left to pay. A bill whose transaction rolled back is gone,
     * and nothing is sent for it.
     */
    private static function announceRaised(MiningTax $tax): void
    {
        if (!isset(self::$raised[$tax->id])) {
            return;
        }

        unset(self::$raised[$tax->id]);

        try {
            $bill = $tax->fresh();
        } catch (\Throwable $e) {
            $bill = null;
        }

        if (!$bill) {
            return;
        }

        self::send('mining.tax_created', [
            // One per bill. Manager Core's own key was once the period and the
            // character, which two bills of a twice-monthly or weekly month share.
            'idempotency_key' => "tax_created:{$bill->id}",
        ] + self::bill($bill));
    }

    private static function publish(string $topic, array $payload): void
    {
        if (!self::carries($topic)) {
            return;
        }

        DB::afterCommit(function () use ($topic, $payload) {
            self::send($topic, $payload);
        });
    }

    private static function carries(string $topic): bool
    {
        if (!class_exists(\ManagerCore\Topics::class)) {
            return false;
        }

        // A Manager Core older than a topic would log a warning for every
        // publish of it. The event starts going out once Manager Core is updated.
        return \ManagerCore\Topics::describe($topic) !== null;
    }

    private static function send(string $topic, array $payload): void
    {
        try {
            \ManagerCore\Topics::publish($topic, $payload);
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not publish {$topic} to Manager Core", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
