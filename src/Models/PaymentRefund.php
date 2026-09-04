<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A refund of held account balance.
 *
 * The balance comes down when the refund is recorded, so what a member is owed
 * is correct straight away. The row then waits for the ISK to be seen leaving
 * the corporation wallet before it counts as confirmed. Nothing here sends
 * money: no ESI endpoint can, so the transfer is always somebody doing it in
 * game.
 */
class PaymentRefund extends Model
{
    protected $table = 'mining_manager_payment_refunds';

    protected $fillable = [
        'credit_id',
        'character_id',
        'amount',
        'reason',
        'status',
        'transaction_id',
        'refunded_by',
        'confirmed_at',
        'confirmed_by',
        'confirmation_note',
    ];

    protected $casts = [
        'credit_id' => 'integer',
        'character_id' => 'integer',
        'amount' => 'decimal:2',
        'transaction_id' => 'integer',
        'refunded_by' => 'integer',
        'confirmed_at' => 'datetime',
        'confirmed_by' => 'integer',
    ];

    /** Recorded, balance reduced, ISK not yet seen leaving the wallet. */
    public const STATUS_PENDING = 'pending';

    /** An outgoing transfer has been matched to this refund. */
    public const STATUS_CONFIRMED = 'confirmed';

    /**
     * Was this proved by the wallet, or asserted by a person?
     *
     * The distinction is the whole point of matching transfers in the first
     * place. A matched refund is backed by a transaction anybody can go and
     * look at; a hand-confirmed one is a director saying the money went out.
     * Both are legitimate, they are not equally strong, and the page must not
     * present them as though they were.
     */
    public function wasMatchedToTransfer(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->transaction_id !== null;
    }

    public function wasConfirmedByHand(): bool
    {
        return $this->status === self::STATUS_CONFIRMED && $this->transaction_id === null;
    }

    public function credit()
    {
        return $this->belongsTo(PaymentCredit::class, 'credit_id');
    }

    public function character()
    {
        return $this->belongsTo(\Seat\Eveapi\Models\Character\CharacterInfo::class, 'character_id', 'character_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * Refunds recorded but not yet seen leaving the wallet.
     *
     * The number worth watching: every row here is money the corporation has
     * agreed to return and has not.
     */
    public static function outstandingTotal(?int $characterId = null): float
    {
        $query = static::pending();

        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        return (float) $query->sum('amount');
    }
}
