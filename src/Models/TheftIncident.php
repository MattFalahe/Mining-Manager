<?php

namespace MiningManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Carbon\Carbon;

class TheftIncident extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'theft_incidents';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'character_id',
        'character_name',
        'corporation_id',
        'mining_tax_id',
        'incident_date',
        'mining_date_from',
        'mining_date_to',
        'ore_value',
        'tax_owed',
        'quantity_mined',
        'status',
        'severity',
        'notes',
        'resolved_at',
        'resolved_by',
        'notified_at',
        'is_active_theft',
        'last_activity_at',
        'activity_count',
        'on_theft_list',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'incident_date' => 'datetime',
        'mining_date_from' => 'date',
        'mining_date_to' => 'date',
        'ore_value' => 'decimal:2',
        'tax_owed' => 'decimal:2',
        'quantity_mined' => 'integer',
        'resolved_at' => 'datetime',
        'notified_at' => 'datetime',
        'resolved_by' => 'integer',
        'is_active_theft' => 'boolean',
        'last_activity_at' => 'datetime',
        'activity_count' => 'integer',
        'on_theft_list' => 'boolean',
    ];

    /**
     * Get the character that this incident belongs to.
     * May return null for unregistered characters.
     */
    public function character()
    {
        return $this->belongsTo(CharacterInfo::class, 'character_id', 'character_id');
    }

    /**
     * Get the corporation that this incident belongs to.
     * May return null if corporation not in SeAT.
     */
    public function corporation()
    {
        return $this->belongsTo(CorporationInfo::class, 'corporation_id', 'corporation_id');
    }

    /**
     * Get the mining tax record associated with this incident.
     */
    public function miningTax()
    {
        return $this->belongsTo(MiningTax::class, 'mining_tax_id');
    }

    /**
     * Scope a query to only include unresolved incidents.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', ['detected', 'investigating']);
    }

    /**
     * Scope a query to only include active thefts in progress.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveThefts($query)
    {
        return $query->where('is_active_theft', true)
                     ->whereIn('status', ['detected', 'investigating']);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by severity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $severity
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope a query to get recent incidents.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('incident_date', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Scope a query to only include incidents on the theft list.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnTheftList($query)
    {
        return $query->where('on_theft_list', true)
                     ->whereIn('status', ['detected', 'investigating']);
    }

    /**
     * Scope a query to only include removed/paid incidents.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRemovedPaid($query)
    {
        return $query->where('status', 'removed_paid');
    }

    /**
     * Scope a query to filter by character.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $characterId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCharacter($query, $characterId)
    {
        return $query->where('character_id', $characterId);
    }

    /**
     * Scope a query to filter by corporation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $corporationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCorporation($query, $corporationId)
    {
        return $query->where('corporation_id', $corporationId);
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|Carbon $startDate
     * @param string|Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('incident_date', [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay()
        ]);
    }

    /**
     * Mark the incident as resolved.
     *
     * @param int $userId
     * @param string|null $notes
     * @return bool
     */
    public function resolve(int $userId, ?string $notes = null)
    {
        $this->status = 'resolved';
        $this->resolved_at = Carbon::now();
        $this->resolved_by = $userId;
        $this->on_theft_list = false;
        $this->is_active_theft = false;

        if ($notes) {
            $this->notes = $notes;
        }

        return $this->save();
    }

    /**
     * Mark the incident as under investigation.
     *
     * @param string|null $notes
     * @return bool
     */
    public function markInvestigating(?string $notes = null)
    {
        $this->status = 'investigating';

        if ($notes) {
            $this->notes = $notes;
        }

        return $this->save();
    }

    /**
     * Mark the incident as a false alarm.
     *
     * @param int $userId
     * @param string|null $notes
     * @return bool
     */
    public function markFalseAlarm(int $userId, ?string $notes = null)
    {
        $this->status = 'false_alarm';
        $this->resolved_at = Carbon::now();
        $this->resolved_by = $userId;
        $this->on_theft_list = false;
        $this->is_active_theft = false;

        if ($notes) {
            $this->notes = $notes;
        }

        return $this->save();
    }

    /**
     * Get the Bootstrap badge class for the severity level.
     *
     * @return string
     */
    public function getSeverityBadgeClass()
    {
        return [
            'low' => 'badge-info',
            'medium' => 'badge-warning',
            'high' => 'badge-danger',
            'critical' => 'badge-dark',
        ][$this->severity] ?? 'badge-secondary';
    }

    /**
     * Get the Bootstrap badge class for the status.
     *
     * @return string
     */
    public function getStatusBadgeClass()
    {
        return [
            'detected' => 'badge-warning',
            'investigating' => 'badge-info',
            'resolved' => 'badge-success',
            'false_alarm' => 'badge-secondary',
            'removed_paid' => 'badge-success',
        ][$this->status] ?? 'badge-secondary';
    }

    /**
     * Get formatted ore value with ISK suffix.
     *
     * @return string
     */
    public function getFormattedOreValue()
    {
        return number_format($this->ore_value, 2) . ' ISK';
    }

    /**
     * Get formatted tax owed with ISK suffix.
     *
     * @return string
     */
    public function getFormattedTaxOwed()
    {
        return number_format($this->tax_owed, 2) . ' ISK';
    }

    /**
     * Check if incident is resolved.
     *
     * @return bool
     */
    public function isResolved()
    {
        return in_array($this->status, ['resolved', 'false_alarm']);
    }

    /**
     * Check if incident is unresolved.
     *
     * @return bool
     */
    public function isUnresolved()
    {
        return !$this->isResolved();
    }

    /**
     * Get the character name (uses cached name for unregistered characters).
     *
     * @return string
     */
    public function getCharacterName()
    {
        if ($this->character) {
            return $this->character->name;
        }

        return $this->character_name ?? "Character {$this->character_id}";
    }

    /**
     * Get the corporation name (uses cached name if available).
     *
     * @return string
     */
    public function getCorporationName()
    {
        if ($this->corporation) {
            return $this->corporation->name;
        }

        return 'Unknown Corporation';
    }

    /**
     * Mark incident as active theft (character continues mining).
     *
     * @param float $newMiningValue
     * @param string|null $notes
     * @return void
     */
    public function markAsActiveTheft(float $newMiningValue = 0, ?string $notes = null): void
    {
        $newTotalValue = $this->ore_value + $newMiningValue;
        $newSeverity = $this->calculateSeverity($newTotalValue);

        $updateData = [
            'is_active_theft' => true,
            'last_activity_at' => now(),
            'severity' => $newSeverity,
        ];

        if ($notes) {
            $updateData['notes'] = $notes;
        }

        // Atomically apply non-incrementing field updates plus the two
        // counter increments. Two queries here instead of one because
        // Eloquent's incrementEach() doesn't accept the $extra array
        // that single-column increment() does.
        //
        // Previously this used a single update() with DB::raw string
        // interpolation:
        //
        //     'ore_value' => DB::raw("ore_value + {$newMiningValue}")
        //
        // which is functionally safe given the float type hint, but is
        // (a) locale-fragile — PHP's float-to-string conversion uses the
        //     locale's decimal separator, so a server with German locale
        //     would produce "ore_value + 1234,56" → SQL syntax error,
        // (b) brittle to refactoring — if someone removes the type hint
        //     or passes a string-cast value, it becomes injectable.
        //
        // incrementEach uses bound parameters via the query builder, so
        // both concerns are gone.
        self::where('id', $this->id)->update($updateData);
        self::where('id', $this->id)->incrementEach([
            'activity_count' => 1,
            'ore_value' => $newMiningValue,
        ]);

        $this->refresh();
    }

    /**
     * Calculate severity based on ore value and tax owed.
     * Uses max(taxOwed, oreValue) to match TheftDetectionService logic.
     *
     * @param float $oreValue
     * @return string (low, medium, high, critical)
     */
    protected function calculateSeverity(float $oreValue): string
    {
        $value = max($this->tax_owed ?? 0, $oreValue);

        if ($value >= 500000000) { // 500M ISK or more
            return 'critical';
        } elseif ($value >= 200000000) { // 200M ISK or more
            return 'high';
        } elseif ($value >= 50000000) { // 50M ISK or more
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Remove character from theft list (taxes paid).
     *
     * @param string $reason
     * @return bool
     */
    public function removeFromList(string $reason = 'paid')
    {
        $this->on_theft_list = false;
        $this->status = 'removed_paid';
        $this->resolved_at = now();
        $this->notes = ($this->notes ? $this->notes . "\n\n" : '')
            . "Removed from theft list: Taxes paid on " . now()->format('Y-m-d H:i:s');
        return $this->save();
    }

    /**
     * Add character back to theft list.
     *
     * @return bool
     */
    public function addToList()
    {
        $this->on_theft_list = true;
        if ($this->status === 'removed_paid') {
            $this->status = 'detected';
        }
        return $this->save();
    }
}
