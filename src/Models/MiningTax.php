<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;

class MiningTax extends Model
{
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
        'amount_owed',
        'amount_paid',
        'status',
        'calculated_at',
        'paid_at',
        'last_reminder_sent',
        'reminder_count',
        'transaction_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'month' => 'date',
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
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnpaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    /**
     * Scope a query to only include overdue taxes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    /**
     * Scope a query to only include paid taxes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope a query to filter by month.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $month
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForMonth($query, $month)
    {
        return $query->where('month', $month);
    }

    /**
     * Check if tax is overdue.
     *
     * @return bool
     */
    public function isOverdue()
    {
        if ($this->status === 'paid' || $this->status === 'waived') {
            return false;
        }

        $gracePeriod = config('mining-manager.tax_payment.grace_period_days', 7);
        $dueDate = $this->month->copy()->addMonth()->addDays($gracePeriod);

        return now()->greaterThan($dueDate);
    }

    /**
     * Get remaining balance.
     *
     * @return float
     */
    public function getRemainingBalance()
    {
        return $this->amount_owed - $this->amount_paid;
    }

    /**
     * Check if fully paid.
     *
     * @return bool
     */
    public function isFullyPaid()
    {
        return $this->amount_paid >= $this->amount_owed;
    }

    /**
     * Get payment percentage.
     *
     * @return float
     */
    public function getPaymentPercentage()
    {
        if ($this->amount_owed <= 0) {
            return 100;
        }

        return ($this->amount_paid / $this->amount_owed) * 100;
    }
}
