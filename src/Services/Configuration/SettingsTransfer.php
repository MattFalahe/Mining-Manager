<?php

namespace MiningManager\Services\Configuration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carries an install's settings into tax profiles without changing what any
 * of them does.
 *
 * Before tax profiles a setting could have a global copy and a copy per
 * corporation, and which one counted depended on the code reading it. Billing
 * ran as the Moon Owner, so it read the Moon Owner's copy first. Reminders and
 * the billing period were read before any corporation was set, so they read
 * the global copy. Each value here is taken from the copy the plugin uses
 * today and written where the registry says it now lives:
 *
 * - each configured corporation gets a complete profile, so none falls back
 *   to Global, which from now on is the profile for non-members;
 * - the Global profile's moon rates become what non-members pay today;
 * - install-wide settings get one global value;
 * - the plugin's own records move to one global row each.
 *
 * Nothing is deleted. A copy the plugin stops reading stays where it is, and
 * the plan lists it.
 *
 * Some things change in effect from the day the transfer runs, and the plan
 * lists the ones that apply to the install it runs on:
 *
 * - a configured corporation with moon rates or a moon ore choice of its own
 *   gets them at the Moon Owner's refineries, where until now the Moon
 *   Owner's applied to everyone;
 * - "Tax All Moon Ore" taxes the moon ore members mine at other corporations'
 *   refineries, as its label always said;
 * - non-members are kept out by Global moon rates of 100%, so "Allow Guest
 *   Mining" switched off becomes those rates, and rates already at 100% now
 *   flag a theft as soon as the mining arrives.
 */
class SettingsTransfer
{
    public const DONE_KEY = 'settings.transferred_at';
    public const REPORT_KEY = 'settings.transfer_report';
    public const CONFIGURED_KEY = 'general.configured_corporations';

    private const TABLE = 'mining_manager_settings';

    /** Never shown in a plan or a report. */
    private const SECRET_KEYS = [
        'janice_api_key',
        'notifications.slack_webhook_url',
    ];

    /** Read while billing, matching payments or showing tax pages, all of which run as the Moon Owner. */
    private const READ_AS_MOON_OWNER = [
        'payment.match_tolerance',
        'payment.accept_alt_characters',
        'payment.cascade_remainder',
        'payment.hold_surplus_as_credit',
        'payment.minimum_tax_amount',
        'payment.minimum_tax_behavior',
        'exemptions.enabled',
        'exemptions.threshold',
        'exemptions.grace_period_days',
        'tax_rates.tax_code_length',
        'tax_rates.tax_code_expiration_buffer',
        'tax_rates.tax_payment_deadline_days',
        'tax_rates.tax_wallet_division',
        'tax_rates.auto_generate_tax_codes',
    ];

    private const PERIOD_KEYS = [
        'tax_rates.tax_calculation_period',
        'tax_rates.tax_calculation_period_pending',
        'tax_rates.tax_calculation_period_effective_from',
    ];

    private const PREFIX_KEY = 'tax_rates.tax_code_prefix';

    /** rarity => [member rate key, guest rate key] */
    private const MOON_RATES = [
        'r4' => ['tax_rates.moon_ore.r4', 'guest_tax_rates.moon_ore.r4'],
        'r8' => ['tax_rates.moon_ore.r8', 'guest_tax_rates.moon_ore.r8'],
        'r16' => ['tax_rates.moon_ore.r16', 'guest_tax_rates.moon_ore.r16'],
        'r32' => ['tax_rates.moon_ore.r32', 'guest_tax_rates.moon_ore.r32'],
        'r64' => ['tax_rates.moon_ore.r64', 'guest_tax_rates.moon_ore.r64'],
    ];

    private const MOON_SELECTORS = [
        'tax_selector.all_moon_ore',
        'tax_selector.only_corp_moon_ore',
        'tax_selector.no_moon_ore',
    ];

    private const MOON_CHOICES = [
        'all' => 'all moon ore',
        'ours' => "only the Moon Owner's moon ore",
        'none' => 'no moon ore',
    ];

    /** @var array<string, array<string|int, object>> key => ['global' | corporation_id => row] */
    private array $rows = [];

    /** @var array<string, object|null> */
    private array $firstRows = [];

    /**
     * Whether this install's settings have been transferred already.
     */
    public function done(): bool
    {
        return DB::table(self::TABLE)
            ->where('key', self::DONE_KEY)
            ->whereNull('corporation_id')
            ->exists();
    }

    /**
     * The report stored when the transfer ran, or null before it has.
     */
    public function storedReport(): ?array
    {
        $report = DB::table(self::TABLE)
            ->where('key', self::REPORT_KEY)
            ->whereNull('corporation_id')
            ->value('value');

        return $report ? (json_decode($report, true) ?: null) : null;
    }

    /**
     * What the transfer would write, and why, without writing anything.
     *
     * Values in the plan are raw, secrets included, because apply() writes
     * them. Show it through report().
     */
    public function plan(): array
    {
        $this->load();

        $moonOwner = (int) $this->cast($this->readGlobally('general.moon_owner_corporation_id')) ?: null;
        $configured = $this->configured($moonOwner);
        $switch = $this->readGlobally('features.allow_guest_mining');
        $guestsAllowed = $switch ? (bool) $this->cast($switch) : true;

        $writes = [];
        $kept = 0;
        $target = function (string $key, ?int $corporationId, ?object $source, string $reason) use (&$writes, &$kept) {
            // No copy anywhere: the default applied and still does.
            if ($source === null) {
                return;
            }

            // A copy saved with another type but reading the same counts as
            // already there: rates saved as integers read as the same rates.
            $current = $this->row($key, $corporationId);
            if ($current !== null && $current->value === $source->value && $this->cast($current) == $this->cast($source)) {
                $kept++;

                return;
            }

            $writes[] = [
                'key' => $key,
                'corporation_id' => $corporationId,
                'from' => $current ? ['value' => $current->value, 'type' => $current->type] : null,
                'to' => ['value' => $source->value, 'type' => $source->type],
                'reason' => $reason,
            ];
        };

        // Exactly the corporations treated as members today, so nobody moves
        // between member and non-member.
        $target(self::CONFIGURED_KEY, null, (object) ['value' => json_encode($configured), 'type' => 'json'], 'the corporations treated as members today');

        $nonMembersPaid = [];
        $globalRates = [];

        foreach (array_merge([null], $configured) as $profile) {
            // Every bill used the global period, whichever corporation it was for.
            foreach (self::PERIOD_KEYS as $key) {
                $target($key, $profile, $this->readGlobally($key), 'the billing period every bill used');
            }

            // Codes were made while billing ran as the Moon Owner.
            $target(self::PREFIX_KEY, $profile, $this->readAs(self::PREFIX_KEY, $moonOwner), 'the prefix codes were made with');

            foreach (self::MOON_RATES as $rarity => [$memberKey, $guestKey]) {
                if ($profile !== null) {
                    $own = $this->row($memberKey, $profile);
                    $target($memberKey, $profile, $own ?? $this->readAs($memberKey, $moonOwner), $own ? 'its own moon rate' : 'the moon rate its members paid');
                } elseif ($configured) {
                    // Non-members paid the guest rate where one was saved, and
                    // the Moon Owner's member rate where not.
                    $guest = $this->readAs($guestKey, $moonOwner);
                    $guest = $guest !== null && $this->cast($guest) !== null ? $guest : null;
                    $paid = $guest ?? $this->readAs($memberKey, $moonOwner);
                    $nonMembersPaid[$rarity] = $this->moonRate($paid, $rarity);

                    if (!$guestsAllowed) {
                        $target($memberKey, null, (object) ['value' => '100', 'type' => 'float'], 'Allow Guest Mining was off, and 100% is how non-members are kept out now');
                        $globalRates[$rarity] = 100.0;

                        continue;
                    }

                    $target($memberKey, null, $paid, $guest ? 'what non-members paid: the guest rate' : 'what non-members paid: the member rate, as no guest rate was saved');
                    $globalRates[$rarity] = $nonMembersPaid[$rarity];
                }
            }

            foreach (self::MOON_SELECTORS as $key) {
                if ($profile !== null) {
                    $own = $this->row($key, $profile);
                    $target($key, $profile, $own ?? $this->readAs($key, $moonOwner), $own ? 'its own moon ore choice' : 'the moon ore choice its members were taxed by');
                } elseif ($configured) {
                    $target($key, null, $this->readAs($key, $moonOwner), "the Moon Owner's moon ore choice, which non-members were taxed by");
                }
            }

            // Members' own mining was taxed as their own corporation.
            if ($profile !== null) {
                foreach (SettingsRegistry::keys(SettingsRegistry::PROFILE) as $key) {
                    if (in_array($key, self::PERIOD_KEYS, true) || $key === self::PREFIX_KEY
                        || in_array($key, self::MOON_SELECTORS, true) || str_starts_with($key, 'tax_rates.moon_ore.')) {
                        continue;
                    }

                    $target($key, $profile, $this->readAs($key, $profile), "what its members' own mining was taxed by");
                }
            }
        }

        $superseded = [];
        $supersede = function (string $key, ?object $inEffect) use (&$superseded) {
            foreach ($this->rows[$key] ?? [] as $corporationId => $row) {
                if ($corporationId === 'global' || ($inEffect !== null && $row->value === $inEffect->value)) {
                    continue;
                }

                $superseded[] = [
                    'key' => $key,
                    'corporation_id' => (int) $corporationId,
                    'value' => $row->value,
                    'in_effect' => $inEffect ? $inEffect->value : null,
                ];
            }
        };

        foreach (SettingsRegistry::keys(SettingsRegistry::INSTALL) as $key) {
            if ($key === self::CONFIGURED_KEY) {
                continue;
            }

            $asMoonOwner = in_array($key, self::READ_AS_MOON_OWNER, true);
            $source = $asMoonOwner ? $this->readAs($key, $moonOwner) : $this->readGlobally($key);

            $target($key, null, $source, $asMoonOwner ? "the Moon Owner's copy, which billing and payments read" : 'the global copy, which the plugin read');
            $supersede($key, $source);
        }

        foreach (SettingsRegistry::keys(SettingsRegistry::STATE) as $key) {
            if (in_array($key, [self::DONE_KEY, self::REPORT_KEY], true)) {
                continue;
            }

            $source = $this->newest($key);
            $target($key, null, $source, 'the latest record, kept once');
            $supersede($key, $source);
        }

        return [
            'moon_owner' => $moonOwner,
            'configured' => $configured,
            'writes' => $writes,
            'kept' => $kept,
            'superseded' => $superseded,
            'effects' => $this->effects($configured, $moonOwner, $guestsAllowed, $nonMembersPaid, $globalRates),
        ];
    }

    /**
     * Run the transfer once. Returns the report it stored, or the stored one
     * when the transfer has run already.
     */
    public function apply(): array
    {
        if ($this->done()) {
            return $this->storedReport() ?? [];
        }

        [$plan, $report] = DB::transaction(function () {
            $plan = $this->plan();
            $now = now();

            foreach ($plan['writes'] as $write) {
                $this->put($write['key'], $write['corporation_id'], $write['to']['value'], $write['to']['type'], $now);
            }

            $report = $this->report($plan, $now->toDateTimeString());

            $this->put(self::REPORT_KEY, null, json_encode($report), 'json', $now);
            $this->put(self::DONE_KEY, null, $now->toDateTimeString(), 'string', $now);

            return [$plan, $report];
        });

        // A read as any corporation may have cached the global copy it fell
        // back to, so every context goes.
        $contexts = array_merge(['global'], $plan['configured']);
        foreach (array_unique(array_column($plan['writes'], 'key')) as $key) {
            foreach ($contexts as $context) {
                Cache::forget(SettingsManagerService::CACHE_PREFIX . $context . '_' . $key);
            }
        }

        Log::info('Mining Manager: settings transferred into tax profiles', [
            'configured' => $report['configured'],
            'written' => $report['written'],
            'kept' => $report['kept'],
            'effects' => $report['effects'],
        ]);

        return $report;
    }

    /**
     * A plan as it is shown and stored: secrets hidden, long values cut, and
     * short enough for the settings table's TEXT column.
     */
    public function report(array $plan, ?string $transferredAt = null): array
    {
        $report = [
            'transferred_at' => $transferredAt,
            'moon_owner' => $plan['moon_owner'],
            'configured' => $plan['configured'],
            'written' => count($plan['writes']),
            'kept' => $plan['kept'],
            'effects' => $plan['effects'],
            'writes' => array_map(fn (array $write) => [
                'key' => $write['key'],
                'corporation_id' => $write['corporation_id'],
                'from' => $write['from'] ? self::display($write['key'], $write['from']['value']) : null,
                'to' => self::display($write['key'], $write['to']['value']),
                'reason' => $write['reason'],
            ], $plan['writes']),
            'superseded' => array_map(fn (array $copy) => [
                'key' => $copy['key'],
                'corporation_id' => $copy['corporation_id'],
                'value' => self::display($copy['key'], $copy['value']),
                'in_effect' => self::display($copy['key'], $copy['in_effect']),
            ], $plan['superseded']),
        ];

        foreach (['writes', 'superseded'] as $list) {
            while (strlen(json_encode($report)) > 60000 && count($report[$list]) > 20) {
                $report[$list] = array_slice($report[$list], 0, intdiv(count($report[$list]), 2));
                $report[$list . '_cut'] = true;
            }
        }

        return $report;
    }

    /**
     * A stored value as it may be shown.
     */
    public static function display(string $key, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (in_array($key, self::SECRET_KEYS, true)) {
            return '(hidden)';
        }

        return mb_strlen($value) > 100 ? mb_substr($value, 0, 97) . '...' : $value;
    }

    /**
     * What changes in effect from the day the transfer runs, in words.
     *
     * @param array<int, int> $configured
     * @param array<string, float> $nonMembersPaid rarity => the moon rate non-members paid
     * @param array<string, float> $globalRates rarity => the Global profile's moon rate after the transfer
     * @return array<int, string>
     */
    private function effects(array $configured, ?int $moonOwner, bool $guestsAllowed, array $nonMembersPaid, array $globalRates): array
    {
        $effects = [];
        $tickers = DB::table('corporation_infos')->whereIn('corporation_id', $configured)->pluck('ticker', 'corporation_id');
        $label = fn (int $id) => isset($tickers[$id]) ? "[{$tickers[$id]}]" : "Corporation {$id}";
        $percent = fn (float $rate) => rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
        $rates = fn (array $byRarity) => implode(', ', array_map(fn (string $rarity, float $rate) => strtoupper($rarity) . ' ' . $percent($rate), array_keys($byRarity), $byRarity));

        $ownerChoice = $this->moonChoice(fn (string $key) => $this->readAs($key, $moonOwner));

        foreach ($configured as $corporationId) {
            $choice = $this->moonChoice(fn (string $key) => $this->row($key, $corporationId) ?? $this->readAs($key, $moonOwner));

            if ($corporationId !== $moonOwner) {
                foreach (self::MOON_RATES as $rarity => [$memberKey]) {
                    $own = $this->row($memberKey, $corporationId);
                    if ($own === null) {
                        continue;
                    }

                    $paidRate = $this->moonRate($this->readAs($memberKey, $moonOwner), $rarity);

                    if ((float) $this->cast($own) !== $paidRate) {
                        $effects[] = sprintf(
                            "%s members pay their own %s rate at the Moon Owner's refineries: %s, where they paid %s.",
                            $label($corporationId),
                            strtoupper($rarity),
                            $percent((float) $this->cast($own)),
                            $percent($paidRate)
                        );
                    }
                }

                if ($choice !== $ownerChoice) {
                    $effects[] = sprintf(
                        "%s members are taxed by its own moon ore choice at the Moon Owner's refineries: %s, where the Moon Owner's choice of %s applied.",
                        $label($corporationId),
                        self::MOON_CHOICES[$choice],
                        self::MOON_CHOICES[$ownerChoice]
                    );
                }
            }

            if ($choice === 'all') {
                $effects[] = sprintf(
                    "%s taxes all moon ore, so the moon ore its members mine at other corporations' refineries is taxed too.",
                    $label($corporationId)
                );
            }
        }

        if ($configured && !$guestsAllowed) {
            $effects[] = "Allow Guest Mining was off, so the Global profile's moon rates are 100%, which is how non-members are kept out now."
                . (min($nonMembersPaid) < 100 ? ' Until now they were billed ' . $rates($nonMembersPaid) . '.' : '')
                . ' Their mining at your refineries still flags a theft at once, and paying the bill now closes it.';
        } elseif ($globalRates && min($globalRates) >= 100) {
            $effects[] = "The Global profile's moon rates are all 100%, which now means non-members may not mine at your refineries: their mining there flags a theft as soon as it arrives, not once a bill is overdue, and paying the bill closes it.";
        }

        return $effects;
    }

    /**
     * A moon rate as the tax calculation reads it, the configured default
     * standing in where no copy was saved.
     */
    private function moonRate(?object $row, string $rarity): float
    {
        return $row ? (float) $this->cast($row) : (float) config("mining-manager.tax_rates.moon_ore.{$rarity}", 0);
    }

    /**
     * The moon ore choice the given copies make, decided the way the tax
     * calculation decides it: no moon ore wins, then only the Moon Owner's.
     */
    private function moonChoice(\Closure $copy): string
    {
        $value = function (string $key, bool $default) use ($copy) {
            $row = $copy($key);

            return $row ? $this->cast($row) : $default;
        };

        if (!empty($value('tax_selector.no_moon_ore', false))) {
            return 'none';
        }

        if (!empty($value('tax_selector.only_corp_moon_ore', false))) {
            return 'ours';
        }

        return ($value('tax_selector.all_moon_ore', true) ?? true) ? 'all' : 'none';
    }

    /**
     * The corporations treated as members today: every corporation with a
     * settings row of its own, and the Moon Owner.
     *
     * @return array<int, int>
     */
    private function configured(?int $moonOwner): array
    {
        $ids = [];
        foreach ($this->rows as $copies) {
            foreach (array_keys($copies) as $corporationId) {
                if ($corporationId !== 'global') {
                    $ids[] = (int) $corporationId;
                }
            }
        }

        if ($moonOwner) {
            $ids[] = $moonOwner;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function load(): void
    {
        $this->rows = [];
        $this->firstRows = [];

        foreach (DB::table(self::TABLE)->orderBy('id')->get(['id', 'key', 'value', 'type', 'corporation_id', 'updated_at']) as $row) {
            $this->rows[$row->key][$row->corporation_id === null ? 'global' : (int) $row->corporation_id] = $row;
        }
    }

    private function row(string $key, ?int $corporationId): ?object
    {
        return $this->rows[$key][$corporationId ?? 'global'] ?? null;
    }

    /**
     * The copy a read with no corporation set returns: the global one, or
     * failing that whichever row the database hands back first, asked the
     * same way the setting model asks.
     */
    private function readGlobally(string $key): ?object
    {
        if ($row = $this->row($key, null)) {
            return $row;
        }

        if (!array_key_exists($key, $this->firstRows)) {
            $this->firstRows[$key] = empty($this->rows[$key]) ? null : DB::table(self::TABLE)->where('key', $key)->first();
        }

        return $this->firstRows[$key];
    }

    /**
     * The copy a read as this corporation returns: its own, else the global one.
     */
    private function readAs(string $key, ?int $corporationId): ?object
    {
        if ($corporationId === null) {
            return $this->readGlobally($key);
        }

        return $this->row($key, $corporationId) ?? $this->row($key, null);
    }

    /**
     * The most recently written copy of a record the plugin keeps for itself.
     */
    private function newest(string $key): ?object
    {
        $copies = array_values($this->rows[$key] ?? []);
        if (!$copies) {
            return null;
        }

        usort($copies, fn ($a, $b) => strcmp((string) $b->updated_at, (string) $a->updated_at));

        return $copies[0];
    }

    /**
     * A stored value as the setting model hands it to the plugin.
     */
    private function cast(?object $row)
    {
        if ($row === null) {
            return null;
        }

        return match ($row->type) {
            'boolean' => (bool) $row->value,
            'integer' => (int) $row->value,
            'float', 'decimal' => (float) $row->value,
            'array', 'json' => json_decode((string) $row->value, true),
            default => $row->value,
        };
    }

    private function put(string $key, ?int $corporationId, ?string $value, ?string $type, $now): void
    {
        $match = DB::table(self::TABLE)->where('key', $key);
        $corporationId === null ? $match->whereNull('corporation_id') : $match->where('corporation_id', $corporationId);

        if ((clone $match)->exists()) {
            $match->update(['value' => $value, 'type' => $type ?? 'string', 'updated_at' => $now]);

            return;
        }

        DB::table(self::TABLE)->insert([
            'key' => $key,
            'value' => $value,
            'type' => $type ?? 'string',
            'corporation_id' => $corporationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
