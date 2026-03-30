<?php

namespace MiningManager\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * Uses affiliation table which is more reliable than character_infos.
     *
     * @return int|null
     */
    public function getCorporationIdAttribute(): ?int
    {
        // Try affiliation first (more reliable)
        if ($this->affiliation && $this->affiliation->corporation_id) {
            return $this->affiliation->corporation_id;
        }

        // Fallback to character_infos if it has corporation_id
        if ($this->character && isset($this->character->corporation_id)) {
            return $this->character->corporation_id;
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

        // Use explicit due_date if set
        if ($this->due_date) {
            return now()->greaterThan($this->due_date);
        }

        // Fallback: calculate from period_end or month
        $gracePeriod = config('mining-manager.tax_payment.grace_period_days', 7);
        $periodEnd = $this->period_end ?? $this->month->copy()->endOfMonth();
        $dueDate = Carbon::parse($periodEnd)->addDays($gracePeriod);

        return now()->greaterThan($dueDate);
    }

    /**
     * Get remaining balance.
     */
    public function getRemainingBalance()
    {
        return $this->amount_owed - $this->amount_paid;
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
