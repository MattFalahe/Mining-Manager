<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedTransaction extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'mining_manager_processed_transactions';

    /**
     * Disable updated_at since table only has created_at.
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'transaction_id',
        'character_id',
        'tax_id',
        'matched_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'transaction_id' => 'integer',
        'character_id' => 'integer',
        'tax_id' => 'integer',
        'matched_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Check if a transaction has already been processed.
     */
    public static function isProcessed(int $transactionId): bool
    {
        return static::where('transaction_id', $transactionId)->exists();
    }
}
