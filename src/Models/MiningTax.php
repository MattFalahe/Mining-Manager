<?php

namespace MiningManager\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MiningManager\Services\Tax\PartPaymentEpoch;
use MiningManager\Services\Tax\TaxPeriodHelper;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterAffiliation;

class MiningTax extends Model
{
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_taxes';

    /**
     * Statuses that still have money owing on them.
     *
     * A part-paid bill is one of them however late it gets. Its status records
     * how much is covered, not whether it is settled; lateness is worked out from
     * the due date instead (see isOverdue()).
     */
    public const OUTSTANDING_STATUSES = ['unpaid', 'partial', 'overdue'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'month',
        'period_type',
        'period_start',
        'period_end',
        'amount_owed',
        'amount_paid',
        'status',
        'calculated_at',
        'paid_at',
        'last_reminder_sent',
        'reminder_count',
        'transaction_id',
        'notes',
        'due_date',
        'triggered_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'month' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'amount_owed' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'calculated_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_reminder_sent' => 'datetime',
        'reminder_count' => 'integer',
    ];

    /**
     * Get the character that owns the tax record.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the character affiliation (contains corporation_id).
     */
    public function affiliation()
    {
        return $this->belongsTo(CharacterAffiliation::class, 'character_id', 'character_id');
    }

    /**
     * Get the corporation ID for this tax record's character.
     *
     * Current corporation lives in character_affiliations — SeAT dropped
     * the corporation_id column from character_infos in 2019, so any
     * previous fallback reading $this->character->corporation_id was a
     * dead branch (isset() on an Eloquent dynamic attribute with no
     * backing column is always false).
     *
     * @return int|null
     */
    public function getCorporationIdAttribute(): ?int
    {
        if ($this->affiliation && $this->affiliation->corporation_id) {
            return $this->affiliation->corporation_id;
        }

        return null;
    }

    /**
     * Get the formatted period label for display.
     * Monthly: "March 2026", Biweekly: "Mar 1-14, 2026", Weekly: "Mar 3-9, 2026"
     *
     * @return string
     */
    public function getFormattedPeriodAttribute(): string
    {
        $type = $this->period_type ?? 'monthly';
        $start = $this->period_start ?? $this->month;
        $end = $this->period_end;

        if (!$start) {
            return 'Unknown';
        }

        if (!$end) {
            return Carbon::parse($start)->format('F Y');
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        return match ($type) {
            'biweekly' => $start->format('M j') . '-' . $end->format('j, Y'),
            'weekly' => $start->format('M j') . '-' . (
                $start->month === $end->month
                    ? $end->format('j, Y')
                    : $end->format('M j, Y')
            ),
            default => $start->format('F Y'),
        };
    }

    /**
     * Get the tax invoices for this tax record.
     */
    public function taxInvoices()
    {
        return $this->hasMany(TaxInvoice::class, 'mining_tax_id');
    }

    /**
     * Get the tax codes for this tax record.
     */
    public function taxCodes()
    {
        return $this->hasMany(TaxCode::class, 'mining_tax_id');
    }

    /**
     * Scope a query to bills with money still owing on them, part-paid included.
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    /**
     * Scope a query to the bills theft detection treats as unpaid.
     *
     * Unpaid and overdue, always. A part-paid bill joins them only if it was
     * raised after the part-payment cutover: before it, any payment at all took
     * a bill off this list, and bills raised then keep that rule so upgrading
     * does not open incidents over debts nobody was chasing. A bill with no
     * created_at counts as raised before.
     */
    public function scopeUnpaidForTheft($query)
    {
        $epoch = PartPaymentEpoch::get();

        return $query->where(function ($q) use ($epoch) {
            $q->whereIn('status', ['unpaid', 'overdue']);

            if ($epoch !== null) {
                $q->orWhere(function ($partPaid) use ($epoch) {
                    $partPaid->where('status', 'partial')
                        ->where('created_at', '>=', $epoch);
                });
            }
        });
    }

    /**
     * Scope a query to only include unpaid taxes.
     */
    public function scopeUnpaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    /**
     * Scope a query to only include overdue taxes.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    /**
     * Scope a query to only include paid taxes.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope a query to filter by calendar month (for charts and backward compat).
     */
    public function scopeForMonth($query, $month)
    {
        return $query->where('month', $month);
    }

    /**
     * Scope a query to filter by period start date.
     */
    public function scopeForPeriod($query, $periodStart)
    {
        return $query->where('period_start', $periodStart);
    }

    /**
     * Scope a query to filter by period type.
     */
    public function scopeOfType($query, string $periodType)
    {
        return $query->where('period_type', $periodType);
    }

    /**
     * Check if tax is overdue.
     * Uses period_end + grace period (or due_date if set).
     */
    public function isOverdue()
    {
        if ($this->status === 'paid' || $this->status === 'waived') {
            return false;
        }

        return now()->greaterThan($this->effectiveDueDate());
    }

    /**
     * The date payment is due by. Records made before due dates were stored
     * fall back to the end of their period plus the grace period.
     */
    public function effectiveDueDate(): Carbon
    {
        if ($this->due_date) {
            return Carbon::parse($this->due_date);
        }

        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $gracePeriod = (int) $settingsService->getSetting('exemptions.grace_period_days',
            config('mining-manager.tax_payment.grace_period_days', 7));
        $periodEnd = $this->period_end ?? $this->month->copy()->endOfMonth();

        return Carbon::parse($periodEnd)->addDays($gracePeriod);
    }

    /**
     * Whole days from today to the due date: positive while there is time left,
     * 0 on the day itself, negative once it has passed.
     *
     * Counted in calendar days rather than hours, so "2 days left" does not
     * become "1 day left" halfway through the afternoon.
     */
    public function daysUntilDue(): int
    {
        $today = now()->startOfDay();
        $due = $this->effectiveDueDate()->copy()->startOfDay();

        // Rounded rather than floored so a clock change in a non-UTC install
        // cannot turn a 23 hour day into zero days.
        return (int) round(($due->getTimestamp() - $today->getTimestamp()) / 86400);
    }

    /**
     * What is still to pay: the bill less everything paid against it.
     *
     * Never negative. Anything paid beyond the bill is held as account credit,
     * not owed back on the bill.
     */
    public function getRemainingBalance(): float
    {
        return max(0.0, round((float) $this->amount_owed - (float) ($this->amount_paid ?? 0), 2));
    }

    /**
     * Check if fully paid.
     */
    public function isFullyPaid()
    {
        return $this->amount_paid >= $this->amount_owed;
    }

    /**
     * Get payment percentage.
     */
    public function getPaymentPercentage()
    {
        if ($this->amount_owed <= 0) {
            return 100;
        }

        return ($this->amount_paid / $this->amount_owed) * 100;
    }
}
