<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One slice of a wallet payment applied to one invoice.
 *
 * A single transfer can cover several invoices, so the relationship between a
 * payment and a tax record is many-to-many and lives here. ProcessedTransaction
 * still holds the unique claim per transaction ("has this been consumed"); this
 * table records the breakdown ("where did it go, how much, who decided").
 */
class PaymentAllocation extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'mining_manager_payment_allocations';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'transaction_id',
        'credit_id',
        'mining_tax_id',
        'character_id',
        'amount',
        'source',
        'allocated_by',
        'notes',
        'allocated_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'transaction_id' => 'integer',
        'credit_id' => 'integer',
        'mining_tax_id' => 'integer',
        'character_id' => 'integer',
        'amount' => 'decimal:2',
        'allocated_by' => 'integer',
        'allocated_at' => 'datetime',
    ];

    /**
     * How this slice came to be.
     *
     * auto    - matched from a tax code by the verification pipeline
     * manual  - a director assigned it on the wallet verification page
     * cascade - remainder rolled onto a later unpaid invoice
     * credit  - drawn down from a previously held surplus
     */
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CASCADE = 'cascade';
    public const SOURCE_CREDIT = 'credit';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tax()
    {
        return $this->belongsTo(MiningTax::class, 'mining_tax_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function credit()
    {
        return $this->belongsTo(PaymentCredit::class, 'credit_id');
    }

    /**
     * Total actually credited to an invoice, from every payment that touched it.
     *
     * This is the reconciliation counterpart to mining_taxes.amount_paid: the
     * two should agree for anything settled after the verification cutover.
     */
    public static function totalForTax(int $taxId): float
    {
        return (float) static::where('mining_tax_id', $taxId)->sum('amount');
    }

    /**
     * Every slice a single payment was broken into.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function forTransaction(int $transactionId)
    {
        return static::where('transaction_id', $transactionId)
            ->orderBy('id')
            ->get();
    }
}
