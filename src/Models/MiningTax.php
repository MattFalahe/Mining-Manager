<?php

namespace MiningManager\Models;

use MiningManager\Models\Concerns\HasCharacterName;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Tax\PartPaymentEpoch;
use MiningManager\Services\Tax\TaxPeriodHelper;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterAffiliation;

class MiningTax extends Model
{
    use SoftDeletes;
    use HasCharacterName;

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
     * tax_profile on a bill billed by the Global profile. A bill billed by a
     * configured corporation's profile holds that corporation's id, and one
     * from before tax profiles holds nothing.
     */
    public const GLOBAL_PROFILE = 'global';

    private static bool $recordsTaxProfile = false;
    private static int $recordsTaxProfileCheckedAt = 0;

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
        'tax_profile',
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
     * Whether bills record the tax profile that made them yet. A queue worker
     * can start on the new code before the update's migrations have added
     * tax_profile, so a "no" is asked again after a while. A "yes" stays.
     */
    public static function recordsTaxProfile(): bool
    {
        if (!self::$recordsTaxProfile && time() - self::$recordsTaxProfileCheckedAt >= 30) {
            self::$recordsTaxProfileCheckedAt = time();

            try {
                self::$recordsTaxProfile = Schema::hasColumn('mining_taxes', 'tax_profile');
            } catch (\Throwable $e) {
                self::$recordsTaxProfile = false;
            }
        }

        return self::$recordsTaxProfile;
    }

    /**
     * Members' bills: billed by a configured corporation's profile, or for a
     * bill from before tax profiles, whose main is in one now.
     *
     * @param array<int, int> $memberCorporationIds
     */
    public function scopeBilledToMembers($query, array $memberCorporationIds)
    {
        $inMemberCorporationNow = fn ($bills) => $bills->whereIn('character_id', fn ($members) => $members
            ->select('character_id')
            ->from('character_affiliations')
            ->whereIn('corporation_id', $memberCorporationIds));

        if (!self::recordsTaxProfile()) {
            return $query->where($inMemberCorporationNow);
        }

        return $query->where(function ($bills) use ($inMemberCorporationNow) {
            $bills->where(fn ($billed) => $billed->whereNotNull('tax_profile')->where('tax_profile', '!=', self::GLOBAL_PROFILE))
                ->orWhere(fn ($older) => $inMemberCorporationNow($older->whereNull('tax_profile')));
        });
    }

    /**
     * Non-members' bills: billed by the Global profile, or for a bill from
     * before tax profiles, whose main is outside the configured corporations
     * now.
     *
     * @param array<int, int> $memberCorporationIds
     */
    public function scopeBilledToNonMembers($query, array $memberCorporationIds)
    {
        $outsideMemberCorporationsNow = fn ($bills) => $bills->whereIn('character_id', fn ($others) => $others
            ->select('character_id')
            ->from('character_affiliations')
            ->whereNotIn('corporation_id', $memberCorporationIds));

        if (!self::recordsTaxProfile()) {
            return $query->where($outsideMemberCorporationsNow);
        }

        return $query->where(function ($bills) use ($outsideMemberCorporationsNow) {
            $bills->where('tax_profile', self::GLOBAL_PROFILE)
                ->orWhere(fn ($older) => $outsideMemberCorporationsNow($older->whereNull('tax_profile')));
        });
    }

    /**
     * Whether this is a member's bill, by the same rule as billedToMembers().
     *
     * @param array<int, int> $memberCorporationIds
     */
    public function isBilledToMember(array $memberCorporationIds): bool
    {
        if ($this->tax_profile !== null) {
            return $this->tax_profile !== self::GLOBAL_PROFILE;
        }

        return in_array($this->corporation_id, $memberCorporationIds, true);
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
     *
     * The due date is a day, not a moment: paying any time on it is on time,
     * so the bill only becomes late once that day is over.
     */
    public function isOverdue()
    {
        if ($this->status === 'paid' || $this->status === 'waived') {
            return false;
        }

        return now()->greaterThan($this->effectiveDueDate()->copy()->endOfDay());
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
     * The countdown in words: days left to pay, due today, or days late.
     */
    public function dueCountdown(): string
    {
        $days = $this->daysUntilDue();

        if ($days > 0) {
            return trans_choice('mining-manager::taxes.due_in_days', $days, ['count' => $days]);
        }

        if ($days === 0) {
            return trans('mining-manager::taxes.due_today');
        }

        return trans_choice('mining-manager::taxes.days_late', -$days, ['count' => -$days]);
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
