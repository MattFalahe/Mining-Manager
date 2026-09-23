<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * Surplus ISK held against a character.
 *
 * Created when a payment is larger than every invoice it could be applied to.
 * Rather than letting amount_paid run past amount_owed (which makes an invoice
 * look overpaid and loses the surplus), the excess is parked here and drawn
 * down against that character's next unpaid invoice.
 */
class PaymentCredit extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'mining_manager_payment_credits';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'character_id',
        'transaction_id',
        'amount',
        'remaining',
        'source',
        'created_by',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'character_id' => 'integer',
        'transaction_id' => 'integer',
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
        'created_by' => 'integer',
    ];

    public const SOURCE_OVERPAYMENT = 'overpayment';
    public const SOURCE_MANUAL = 'manual';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class, 'credit_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Credit still available to a set of characters, oldest first.
     *
     * Takes a list rather than a single id because a player's invoices and
     * their surplus can sit on different characters of the same account.
     *
     * @param  array  $characterIds
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function availableFor(array $characterIds)
    {
        return static::whereIn('character_id', $characterIds)
            ->where('remaining', '>', 0)
            ->orderBy('created_at');
    }

    /**
     * Total unspent credit across a set of characters.
     */
    public static function balanceFor(array $characterIds): float
    {
        if (empty($characterIds)) {
            return 0.0;
        }

        return (float) static::whereIn('character_id', $characterIds)
            ->where('remaining', '>', 0)
            ->sum('remaining');
    }
}
