<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Seat\Eveapi\Models\Character\CharacterInfo;

class TaxCode extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mining_tax_codes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'mining_tax_id',
        'character_id',
        'code',
        'prefix',
        'status',
        'generated_at',
        'expires_at',
        'used_at',
        'transaction_id',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Get the mining tax record.
     */
    public function miningTax()
    {
        return $this->belongsTo(MiningTax::class, 'mining_tax_id');
    }

    /**
     * Get the character.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Scope a query to only include active codes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope a query to only include used codes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUsed($query)
    {
        return $query->where('status', 'used');
    }

    /**
     * Scope a query to only include expired codes.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($outer) {
            $outer->where('status', 'expired')
                ->orWhere(function ($q) {
                    $q->where('status', 'active')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now());
                });
        });
    }

    /**
     * Check if code is expired.
     *
     * @return bool
     */
    public function isExpired()
    {
        if (!$this->expires_at) {
            return false;
        }

        return now()->greaterThan($this->expires_at);
    }

    /**
     * Get full tax code with prefix.
     *
     * @return string
     */
    public function getFullCode()
    {
        // Use stored prefix if available, otherwise fall back to current setting
        $prefix = $this->prefix ?? self::getPrefix();
        return $prefix . $this->code;
    }

    /**
     * Get the configured tax code prefix from settings.
     *
     * @return string
     */
    public static function getPrefix(): string
    {
        try {
            $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $taxRates = $settings->getTaxRates();
            return $taxRates['tax_code_prefix'] ?? 'TAX-';
        } catch (\Exception $e) {
            return config('mining-manager.wallet.tax_code_prefix', 'TAX-');
        }
    }

    /**
     * Get the configured tax code length from settings.
     *
     * @return int
     */
    public static function getCodeLength(): int
    {
        try {
            $settings = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
            $taxRates = $settings->getTaxRates();
            return (int) ($taxRates['tax_code_length'] ?? 8);
        } catch (\Exception $e) {
            return (int) config('mining-manager.wallet.tax_code_length', 8);
        }
    }

    /**
     * Extract a tax code from free-text (e.g., wallet transaction description).
     *
     * Handles mixed code lengths: tries the current configured length AND all distinct
     * lengths of active/pending codes in the database. This ensures that if the admin
     * changes code length from 8 to 12, existing 8-char codes still match.
     *
     * @param string|null $text The text to search (wallet description, reason field, etc.)
     * @return string|null The extracted code (uppercase, without prefix), or null if no match
     */
    public static function extractCodeFromText(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        // Collect all known prefixes: current setting + any stored in DB
        $currentPrefix = self::getPrefix();
        $storedPrefixes = self::select('prefix')
            ->distinct()
            ->whereNotNull('prefix')
            ->pluck('prefix');

        $prefixes = collect([$currentPrefix])
            ->merge($storedPrefixes)
            ->unique()
            ->filter();

        // Collect all code lengths to try: current setting + actual lengths of active codes
        $currentLength = self::getCodeLength();
        $activeLengths = self::whereIn('status', ['active', 'pending'])
            ->selectRaw('CHAR_LENGTH(code) as code_length')
            ->distinct()
            ->pluck('code_length');

        $lengths = collect([$currentLength])
            ->merge($activeLengths)
            ->unique()
            ->sortDesc() // Try longest first to avoid partial matches
            ->values();

        // Try each prefix + length combination
        foreach ($prefixes as $prefix) {
            $escapedPrefix = preg_quote($prefix, '/');
            foreach ($lengths as $length) {
                if (preg_match('/' . $escapedPrefix . '([A-Z0-9]{' . $length . '})/i', $text, $matches)) {
                    return strtoupper($matches[1]);
                }
            }
        }

        return null;
    }

    /**
     * Generate a unique tax code.
     *
     * @return string
     * @throws \RuntimeException If unable to generate a unique code after max attempts
     */
    public static function generateCode(): string
    {
        $length = self::getCodeLength();
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed ambiguous characters

        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            if (!self::where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Failed to generate unique tax code after ' . $maxAttempts . ' attempts');
    }
}
