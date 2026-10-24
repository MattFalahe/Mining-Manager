<?php

namespace MiningManager\Services\Configuration;

use Carbon\Carbon;
use MiningManager\Models\Setting;
use MiningManager\Models\WebhookConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SettingsManagerService
{
    /**
     * Cache duration in minutes
     */
    const CACHE_DURATION = 60;

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'mining_manager_settings_';

    /**
     * A tax profile's billing period and its queued change, which an import
     * applies through setPeriodFor() rather than writing straight in.
     */
    private const PERIOD_KEYS = [
        'tax_rates.tax_calculation_period',
        'tax_rates.tax_calculation_period_pending',
        'tax_rates.tax_calculation_period_effective_from',
    ];

    /**
     * Active corporation ID for settings context
     *
     * @var int|null
     */
    protected $activeCorporationId = null;

    /**
     * Whether settings are in tax profiles, once known to be. See
     * usesTaxProfiles().
     */
    private static bool $taxProfiles = false;
    private static int $taxProfilesCheckedAt = 0;

    /**
     * The configured corporations for a few seconds, as [until, ids]. Every
     * profile setting read asks for them, and rebuilding a day's tax reads
     * dozens of those per ore.
     *
     * @var array{0: float, 1: array<int>}|null
     */
    private ?array $homeCorporations = null;

    /**
     * Set the active corporation context
     *
     * @deprecated Use getSettingForCorporation() instead to avoid singleton state mutation.
     * @param int|null $corporationId
     * @return self
     */
    public function setActiveCorporation(?int $corporationId): self
    {
        $this->activeCorporationId = $corporationId;
        return $this;
    }

    /**
     * Get the active corporation ID
     *
     * @return int|null
     */
    public function getActiveCorporation(): ?int
    {
        return $this->activeCorporationId;
    }

    /**
     * Whether a corporation has settings of its own: a place on the
     * configured list once settings are in tax profiles, any row before that.
     *
     * @param int $corporationId
     * @return bool
     */
    public function corporationHasCustomSettings(int $corporationId): bool
    {
        if ($this->usesTaxProfiles()) {
            return $this->isConfiguredCorporation($corporationId);
        }

        return Setting::where('corporation_id', $corporationId)->exists();
    }

    /**
     * Whether this install's settings have been carried into tax profiles.
     *
     * Until the update's migration has run, everything reads and writes as it
     * always did. A queue worker can start on the new code before that
     * migration finishes, so a "no" is asked again after a while. A "yes"
     * stays.
     */
    public function usesTaxProfiles(): bool
    {
        if (!self::$taxProfiles && time() - self::$taxProfilesCheckedAt >= 30) {
            self::$taxProfilesCheckedAt = time();

            try {
                self::$taxProfiles = DB::table('mining_manager_settings')
                    ->where('key', SettingsTransfer::DONE_KEY)
                    ->whereNull('corporation_id')
                    ->exists();
            } catch (\Throwable $e) {
                self::$taxProfiles = false;
            }
        }

        return self::$taxProfiles;
    }

    /**
     * The first mining day taxed by tax profiles: the day after settings
     * moved into them, so nothing mined before the update is taxed under
     * rules it was not mined under. Null until they have moved.
     */
    public function profilesTaxFrom(): ?Carbon
    {
        if (!$this->usesTaxProfiles()) {
            return null;
        }

        $movedAt = $this->getSettingForCorporation(SettingsTransfer::DONE_KEY, null);

        try {
            return $movedAt ? Carbon::parse($movedAt)->startOfDay()->addDay() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get a setting as the active corporation, with fallback to config.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->getSettingForCorporation($key, $this->activeCorporationId, $default);
    }

    /**
     * Get a setting for a specific corporation without mutating the active context.
     *
     * @param string $key
     * @param int|null $corporationId
     * @param mixed $default
     * @return mixed
     */
    public function getSettingForCorporation(string $key, ?int $corporationId, $default = null)
    {
        [$corporationId, $exact] = $this->placeFor($key, $corporationId);
        $cacheKey = self::CACHE_PREFIX . ($corporationId ?? 'global') . '_' . $key;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($key, $default, $corporationId, $exact) {
            $setting = $exact
                ? Setting::valueAt($key, $corporationId)
                : Setting::getValue($key, null, $corporationId);

            if ($setting !== null) {
                return $setting;
            }

            $configKey = 'mining-manager.' . $key;
            return config($configKey, $default);
        });
    }

    /**
     * Where a setting is read and written, as [corporation, exactly there].
     *
     * Before tax profiles a key could have a global row and a row per
     * corporation, a read fell back from one to the other, and which copy
     * counted depended on the corporation the caller happened to be acting
     * as. Once settings are in tax profiles the registry decides instead:
     *
     * - an install-wide setting, or one of the plugin's own records, is the
     *   global row whoever asks;
     * - a profile setting is the corporation's own profile when it is
     *   configured, and the Global profile otherwise, because everyone outside
     *   the configured corporations is taxed by Global;
     * - a key being retired, or one the registry does not know, is read the
     *   way it always was until the release that removes it.
     *
     * Profiles never fall back to Global: a configured corporation that has
     * no value for a key gets the default, not the non-members' setting.
     */
    private function placeFor(string $key, ?int $corporationId): array
    {
        if (!$this->usesTaxProfiles()) {
            return [$corporationId, false];
        }

        switch (SettingsRegistry::scope($key)) {
            case SettingsRegistry::INSTALL:
            case SettingsRegistry::STATE:
                return [null, true];
            case SettingsRegistry::PROFILE:
                return [$corporationId && $this->isConfiguredCorporation($corporationId) ? $corporationId : null, true];
            default:
                return [$corporationId, false];
        }
    }

    /**
     * Update a setting as a given corporation without leaving the active
     * corporation changed, the way getSettingForCorporation() reads one.
     */
    public function updateSettingForCorporation(string $key, $value, ?string $type, ?int $corporationId): void
    {
        $previous = $this->activeCorporationId;

        try {
            $this->activeCorporationId = $corporationId;
            $this->updateSetting($key, $value, $type);
        } finally {
            $this->activeCorporationId = $previous;
        }
    }

    /**
     * Update or create a setting that belongs to the install rather than to a
     * corporation.
     *
     * Several commands set an active corporation on this service and leave it
     * set for the rest of their run. Anything written in that window lands on
     * that corporation, which is right for a tax rate and wrong for a fact
     * about the install, such as whether the price provider is answering:
     * those end up scattered across a row per corporation, each with its own
     * half of the story.
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $type
     * @return void
     */
    public function updateGlobalSetting(string $key, $value, ?string $type = null)
    {
        $previous = $this->activeCorporationId;

        try {
            $this->activeCorporationId = null;
            $this->updateSetting($key, $value, $type);
        } finally {
            $this->activeCorporationId = $previous;
        }
    }

    /**
     * Update or create a setting
     * Now supports corporation-specific settings
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $type
     * @return void
     */
    public function updateSetting(string $key, $value, ?string $type = null)
    {
        if ($type === null) {
            $type = $this->detectType($value);
        }

        // Written where it is read, so a value saved as one corporation can
        // never land in a copy that nothing reads.
        [$corporationId] = $this->placeFor($key, $this->activeCorporationId);
        $this->homeCorporations = null;

        // Clear cache BEFORE writing — if another process reads between our
        // DB write and a post-write cache clear, it would populate the cache
        // with the old value (a second's worth of stale reads). Clearing
        // before write means the worst case is a cache miss that re-reads
        // fresh from DB.
        $globalCacheKey = self::CACHE_PREFIX . 'global_' . $key;
        $corpCacheKey = $corporationId
            ? self::CACHE_PREFIX . $corporationId . '_' . $key
            : null;

        Cache::forget($globalCacheKey);
        if ($corpCacheKey) {
            Cache::forget($corpCacheKey);
        }

        Setting::setValue($key, $value, $type, $corporationId);

        // Clear again AFTER the write as belt-and-suspenders — covers the
        // (unlikely) case where another process read+cached during the
        // brief window between pre-clear and write. Idempotent, negligible
        // cost for a setting update.
        Cache::forget($globalCacheKey);
        if ($corpCacheKey) {
            Cache::forget($corpCacheKey);
        }

        // Demoted from info to debug — bulk saves (saveTaxRates,
        // saveGeneralSettings) emit dozens of nearly-identical lines per
        // request. The controller-level batch-complete log line is what
        // operators actually want to see at info; per-key writes are
        // diagnostic-only.
        $corpContext = $corporationId ? " (Corp ID: {$corporationId})" : " (Global)";
        Log::debug("Mining Manager: Setting updated - {$key}{$corpContext}");
    }

    /**
     * Get general settings, database first with config as fallback
     *
     * @return array
     */
    public function getGeneralSettings(): array
    {
        return [
            // NOTE: Despite the name, this is effectively the "Tax Program Corporation ID".
            // This corp owns the moons/structures AND runs the mining tax program.
            // All tax invoices, theft detection, moon notifications, and ledger tracking
            // are scoped to this corp — regardless of ore source (moon, belt, ice, gas).
            // Webhook notifications for moon/theft/tax events are also filtered to only
            // reach webhooks tied to this corp (see WebhookDispatchTrait::getMoonOwnerScopedWebhooks).
            // Other directors' corps on the same SeAT install are excluded from these notifications.
            'moon_owner_corporation_id' => $this->getSetting('general.moon_owner_corporation_id', config('mining-manager.general.moon_owner_corporation_id')),

            // Ore Refining Settings (unified: reads from pricing.refining_efficiency)
            'ore_refining_rate' => $this->getSetting('pricing.refining_efficiency', config('mining-manager.pricing.refining_efficiency', 87.5)),
            
            // Price Provider Settings
            'price_provider' => $this->getSetting('price_provider', config('mining-manager.general.price_provider', 'eve_market')),
            'default_region_id' => $this->getSetting('general.default_region_id', config('mining-manager.general.default_region_id', 10000002)),
            'price_modifier' => $this->getSetting('general.price_modifier', config('mining-manager.general.price_modifier', 0.0)),
            
            // Time Settings - Always UTC for consistency with EVE Online
            // These are hard-coded and not configurable to prevent issues with:
            // - Moon rental bills alignment
            // - Corporation mining ledger timestamps
            // - Tax month boundaries
            'timezone' => 'UTC',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i:s',

            // Payment auto-match toggle. Surfaced here so the General settings
            // blade can render the checkbox's current state. The canonical
            // read site for runtime gating is getPaymentSettings().
            'auto_match_payments' => (bool) $this->getSetting('payment.auto_match_payments', true),

            // Accept payments from any of a player's characters (alt-aware
            // match, v2.0.2+). Same surfacing-pattern as auto_match_payments:
            // the canonical read site is `getPaymentSettings()`; the value
            // is duplicated here so the General settings blade can render
            // the checkbox without juggling two arrays. WalletTransferService
            // consults `getPaymentSettings()['accept_alt_characters']` at
            // match time.
            'accept_alt_characters' => (bool) $this->getSetting('payment.accept_alt_characters', true),

            // Roll the remainder of an oversized payment onto the payer's
            // next-oldest unpaid invoice, and keep going until the money runs
            // out. Covers the common "settles three months in one transfer"
            // case without a director splitting it by hand. Off means a
            // payment only ever touches the one invoice it was matched to.
            'cascade_remainder' => (bool) $this->getSetting('payment.cascade_remainder', true),

            // What to do with money left over once every open invoice is
            // settled. On (default) parks it against the paying character and
            // draws it down when their next invoice is calculated. Off
            // discards it, which is the pre-allocation-ledger behaviour.
            'hold_surplus_as_credit' => (bool) $this->getSetting('payment.hold_surplus_as_credit', true),

            // A standing keyword a member can put in the transfer reason to
            // pay ahead of being invoiced. Unlike a tax code it never expires
            // and is the same for everyone, so it can be pinned in a corp MOTD.
            // Empty disables the feature entirely.
            'upfront_keyword' => trim((string) $this->getSettingForCorporation('payment.upfront_keyword', null, 'MM-UPFRONT')),

            // The mirror of that, for money going the other way. A refund is a
            // corporation_account_withdrawal to a character, and so is an SRP
            // payout or any other reimbursement, so amount and recipient alone
            // cannot tell them apart. Requiring this in the transfer reason
            // means a refund only confirms against a transfer meant to be one.
            // Global for the same reason the other is: one wallet, one keyword.
            'refund_keyword' => trim((string) $this->getSettingForCorporation('payment.refund_keyword', null, 'MM-REFUND')),

            // The canonical read for runtime gating is getFeatureFlags().
            'enable_upfront_payments' => (bool) $this->getSettingForCorporation('features.enable_upfront_payments', null, false),

            // An invoice past its due date is treated as overdue for
            // notification purposes unless this much of it is already paid.
            // Guards the token-payment loophole: 1m against a 1b invoice is
            // 0.1% paid, nowhere near settled, and should not buy the gentler
            // wording forever. The default forgives rounding and price drift,
            // which is the only honest reason to be slightly short. 0 restores
            // the old behaviour where any payment at all softened the tone.
            'overdue_paid_threshold_pct' => (float) $this->getSetting('payment.overdue_paid_threshold_pct', 95),

        ];
    }

    /**
     * Get corporation ID (convenience method)
     * Used throughout the plugin for tax calculations and data filtering.
     *
     * @deprecated Use getTaxProgramCorporationId() instead. This alias is kept
     *             for backward compatibility but delegates to the canonical
     *             accessor. The legacy `general.corporation_id` setting is no
     *             longer consulted.
     * @return int|null
     */
    public function getCorporationId(): ?int
    {
        return $this->getTaxProgramCorporationId();
    }

    /**
     * Get payment settings
     *
     * @return array
     */
    public function getPaymentSettings(): array
    {
        $division = (int) $this->getSetting('tax_rates.tax_wallet_division',
            $this->getSetting('payment.wallet_division', 1)
        );

        // Backwards compatibility: convert 1000-1007 to 1-7
        if ($division >= 1000) {
            $division = $division - 999;
        }

        return [
            // Wallet transfers are the only way to pay.
            'method' => 'wallet',
            'wallet_division' => $division,
            'grace_period_hours' => $this->getSetting('payment.grace_period_hours', 24),
            'match_tolerance' => $this->getSetting('payment.match_tolerance', 100),
            'minimum_tax_amount' => (float) $this->getSetting('payment.minimum_tax_amount',
                config('mining-manager.tax_payment.minimum_tax_amount', 1000000)),
            // Auto-match toggle, read by the scheduled verify-payments run.
            // true (default) applies a matched payment as soon as it is found;
            // false detects the match and leaves it on the wallet verification
            // page for someone to confirm. Useful for installs that want a
            // human-review step before any invoice is updated.
            'auto_match_payments' => (bool) $this->getSetting('payment.auto_match_payments', true),
            'accept_alt_characters' => (bool) $this->getSetting('payment.accept_alt_characters', true),
            'minimum_tax_behavior' => $this->getSetting('payment.minimum_tax_behavior', 'exempt'),

            // Read by PaymentAllocationService when deciding how far a payment
            // reaches. Surfaced in getGeneralSettings() as well so the settings
            // blade can render the checkboxes.
            'cascade_remainder' => (bool) $this->getSetting('payment.cascade_remainder', true),
            'hold_surplus_as_credit' => (bool) $this->getSetting('payment.hold_surplus_as_credit', true),

            // A standing keyword a member can put in the transfer reason to
            // pay ahead of being invoiced. Unlike a tax code it never expires
            // and is the same for everyone, so it can be pinned in a corp MOTD.
            // Empty disables the feature entirely.
            'upfront_keyword' => trim((string) $this->getSettingForCorporation('payment.upfront_keyword', null, 'MM-UPFRONT')),

            // The mirror of that, for money going the other way. A refund is a
            // corporation_account_withdrawal to a character, and so is an SRP
            // payout or any other reimbursement, so amount and recipient alone
            // cannot tell them apart. Requiring this in the transfer reason
            // means a refund only confirms against a transfer meant to be one.
            // Global for the same reason the other is: one wallet, one keyword.
            'refund_keyword' => trim((string) $this->getSettingForCorporation('payment.refund_keyword', null, 'MM-REFUND')),

            // An invoice past its due date is treated as overdue for
            // notification purposes unless this much of it is already paid.
            // Guards the token-payment loophole: 1m against a 1b invoice is
            // 0.1% paid, nowhere near settled, and should not buy the gentler
            // wording forever. The default forgives rounding and price drift,
            // which is the only honest reason to be slightly short. 0 restores
            // the old behaviour where any payment at all softened the tone.
            'overdue_paid_threshold_pct' => (float) $this->getSetting('payment.overdue_paid_threshold_pct', 95),
            // Accept payments from any of a player's characters, not just
            // the exact taxed character. Players routinely send ISK from
            // their wallet-richest alt rather than the alt that mined the
            // ore, so before v2.0.2 those payments dropped on the floor
            // as 'unmatched' even though the tax code was correct. With
            // this on (default), MM matches tax-code-bearing transactions
            // when the paying character shares a SeAT user_id with the
            // taxed character. Set to false for stricter per-character
            // matching (rare; only useful in installs where each
            // character is its own SeAT account).
            'accept_alt_characters' => (bool) $this->getSetting('payment.accept_alt_characters', true),
        ];
    }

    /**
     * Get the Tax Program Corporation ID — the single source of truth for
     * "which corporation runs this SeAT install's mining tax program".
     *
     * Historical note: this value lives in the `general.moon_owner_corporation_id`
     * setting key (preserved to avoid migrations on released installs). The name
     * `moon_owner_corporation_id` is legacy — it refers to the corp that owns
     * the moons/structures, which in practice is also the corp collecting taxes
     * from miners mining those structures. Use this accessor everywhere instead
     * of reading the raw setting to make intent clear.
     *
     * The older `general.corporation_id` setting (used in early versions as a
     * "currently selected" UI-state value) is no longer consulted — it was
     * unreliable (often empty at global scope) and semantically redundant.
     *
     * @return int|null
     */
    public function getTaxProgramCorporationId(): ?int
    {
        $value = $this->getSetting('general.moon_owner_corporation_id');
        return $value ? (int) $value : null;
    }

    /**
     * Get the display name for the configured wallet division.
     *
     * @return string
     */
    public function getWalletDivisionName(): string
    {
        $paymentSettings = $this->getPaymentSettings();
        $division = $paymentSettings['wallet_division'];
        $moonOwnerCorpId = $this->getSetting('general.moon_owner_corporation_id');

        $defaultNames = [
            1 => 'Master Wallet',
            2 => '2nd Wallet Division',
            3 => '3rd Wallet Division',
            4 => '4th Wallet Division',
            5 => '5th Wallet Division',
            6 => '6th Wallet Division',
            7 => '7th Wallet Division',
        ];

        if ($moonOwnerCorpId) {
            try {
                $name = DB::table('corporation_divisions')
                    ->where('corporation_id', $moonOwnerCorpId)
                    ->where('type', 'wallet')
                    ->where('division', $division)
                    ->value('name');

                if (!empty($name)) {
                    return $name;
                }
            } catch (\Exception $e) {
                // Fall through to default
            }
        }

        return $defaultNames[$division] ?? "Division {$division}";
    }

    /**
     * Update payment settings
     *
     * @param array $settings
     * @return void
     */
    public function updatePaymentSettings(array $settings)
    {
        DB::beginTransaction();
        
        try {
            foreach ($settings as $key => $value) {
                $this->updateSetting('payment.' . $key, $value);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get tax rates
     * FIXED: Now retrieves correct field names matching the actual form
     *
     * @return array
     */
    public function getTaxRates(): array
    {
        return array_merge($this->taxRatesFor($this->activeCorporationId), [
            // Wallet division tax payments arrive in
            'tax_wallet_division' => $this->getSetting('tax_rates.tax_wallet_division', 1000),

            // Tax Code Settings
            'tax_code_prefix' => $this->getSetting('tax_rates.tax_code_prefix', 'TAX-'),
            'tax_code_length' => $this->getSetting('tax_rates.tax_code_length', 6),
            'tax_code_expiration_buffer' => $this->getSetting('tax_rates.tax_code_expiration_buffer', 30),
            'auto_generate_tax_codes' => $this->getSetting('tax_rates.auto_generate_tax_codes', true),

            // Tax Period Settings
            'tax_calculation_period' => $this->getSetting('tax_rates.tax_calculation_period', 'monthly'),
            'tax_calculation_period_pending' => $this->getSetting('tax_rates.tax_calculation_period_pending', null),
            'tax_calculation_period_effective_from' => $this->getSetting('tax_rates.tax_calculation_period_effective_from', null),
            'tax_payment_deadline_days' => $this->getSetting('tax_rates.tax_payment_deadline_days', 7),
            'send_tax_reminders' => $this->getSetting('tax_rates.send_tax_reminders', true),
            'tax_reminder_days' => $this->getSetting('tax_rates.tax_reminder_days', 3),
        ]);
    }

    /**
     * The rates of the profile that taxes a corporation's members: its own
     * when it is configured, Global otherwise. Read without moving the active
     * corporation, so a caller can hold several profiles at once.
     *
     * @return array{moon_ore: array<string, mixed>, ice: mixed, ore: mixed, gas: mixed, abyssal_ore: mixed, triglavian_ore: mixed}
     */
    public function taxRatesFor(?int $corporationId): array
    {
        $rate = fn (string $key, float $default) => $this->getSettingForCorporation("tax_rates.{$key}", $corporationId, config("mining-manager.tax_rates.{$key}", $default));

        return [
            // Moon ore tax rates by rarity (percentage)
            'moon_ore' => [
                'r64' => $rate('moon_ore.r64', 15.0),
                'r32' => $rate('moon_ore.r32', 12.0),
                'r16' => $rate('moon_ore.r16', 10.0),
                'r8' => $rate('moon_ore.r8', 8.0),
                'r4' => $rate('moon_ore.r4', 5.0),
            ],

            // Regular ore type tax rates (percentage)
            'ice' => $rate('ice', 10.0),
            'ore' => $rate('ore', 10.0),
            'gas' => $rate('gas', 10.0),
            'abyssal_ore' => $rate('abyssal_ore', 15.0),
            'triglavian_ore' => $rate('triglavian_ore', 10.0),
        ];
    }

    /**
     * Get tax rates for a specific corporation.
     * If the character is a guest miner (not from any configured corporation),
     * uses guest miner tax rates from the Moon Owner Corp config.
     * Guest rate = 0 means actual 0% tax (no fallback to corp rates).
     *
     * @param int|null $characterCorporationId The corporation ID of the character being taxed
     * @return array
     */
    public function getTaxRatesForCorporation(?int $characterCorporationId): array
    {
        // Get base (corp member) tax rates
        $corpTaxRates = $this->getTaxRates();

        // If no character corporation ID provided, use corp rates
        if (!$characterCorporationId) {
            return $corpTaxRates;
        }

        // Build list of "home" corporations: configured corps + moon owner corp
        // Anyone NOT in this list is a guest miner
        $homeCorporationIds = $this->getHomeCorporationIds();

        // If no corporations configured at all, treat everyone as corp member
        if (empty($homeCorporationIds)) {
            return $corpTaxRates;
        }

        // If the character belongs to any configured corporation, use corp member rates
        if (in_array((int) $characterCorporationId, $homeCorporationIds, true)) {
            return $corpTaxRates;
        }

        // Under tax profiles everyone outside the configured corporations is
        // taxed by Global, which took the guest rates over when settings
        // moved into profiles. The guest rows below are only read before then.
        if ($this->usesTaxProfiles()) {
            return array_merge($corpTaxRates, $this->taxRatesFor(null));
        }

        // Character is a guest miner — guest rates from Moon Owner Corp config
        // Guest rate = 0 means actual 0% tax, NOT "use corp rate"
        // Only falls back to corp rate if the guest rate setting does not exist (null)
        $moonOwnerCorpId = $this->getSetting('general.moon_owner_corporation_id');
        $savedContext = $this->activeCorporationId;
        if ($moonOwnerCorpId) {
            $this->activeCorporationId = (int) $moonOwnerCorpId;
        }

        $guestRates = $corpTaxRates; // Start with corp rates as fallback for unconfigured fields

        // Guest moon ore rates — null = not configured (use corp rate), 0 = actual 0%
        foreach (['r64', 'r32', 'r16', 'r8', 'r4'] as $rarity) {
            $guestRate = $this->getSetting("guest_tax_rates.moon_ore.{$rarity}");
            if ($guestRate !== null) {
                $guestRates['moon_ore'][$rarity] = (float) $guestRate;
            }
        }

        // Guest regular ore/ice/gas/abyssal/triglavian rates
        $guestIceRate = $this->getSetting('guest_tax_rates.ice');
        if ($guestIceRate !== null) {
            $guestRates['ice'] = (float) $guestIceRate;
        }

        $guestOreRate = $this->getSetting('guest_tax_rates.ore');
        if ($guestOreRate !== null) {
            $guestRates['ore'] = (float) $guestOreRate;
        }

        $guestGasRate = $this->getSetting('guest_tax_rates.gas');
        if ($guestGasRate !== null) {
            $guestRates['gas'] = (float) $guestGasRate;
        }

        $guestAbyssalRate = $this->getSetting('guest_tax_rates.abyssal_ore');
        if ($guestAbyssalRate !== null) {
            $guestRates['abyssal_ore'] = (float) $guestAbyssalRate;
        }

        $guestTriglavianRate = $this->getSetting('guest_tax_rates.triglavian_ore');
        if ($guestTriglavianRate !== null) {
            $guestRates['triglavian_ore'] = (float) $guestTriglavianRate;
        }

        // Restore context
        $this->activeCorporationId = $savedContext;

        return $guestRates;
    }

    /**
     * Change a tax profile's billing period, the profile for a corporation's
     * members as everywhere else.
     *
     * Queued to TaxPeriodHelper::nextSafeEffectiveDate(), the 3rd, unless
     * asked for today and TaxPeriodHelper::canChangePeriodToday() allows it:
     * changed on any other day, part of the month would be billed twice or
     * not at all. Choosing the period already in force clears any queued
     * change.
     *
     * @return string|null 'today' or 'queued', or null when the period stays as it is
     */
    public function setPeriodFor(?int $corporationId, string $requested, bool $applyNow): ?string
    {
        // Defense in depth: weekly was removed as a selectable period
        // type in v1.0.3+. If a caller somehow passes 'weekly' (e.g.
        // via a bypassed form), coerce to 'monthly' and log it. Prevents
        // the legacy value from being re-introduced into the settings store.
        if ($requested === 'weekly') {
            Log::warning('Mining Manager: Attempted to set tax_calculation_period to deprecated "weekly"; coerced to "monthly". Use biweekly for sub-monthly periods.');
            $requested = 'monthly';
        }

        $periods = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
        $write = fn (string $key, $value) => $this->updateSettingForCorporation($key, $value, 'string', $corporationId);
        $current = $this->getSettingForCorporation('tax_rates.tax_calculation_period', $corporationId, 'monthly');
        $pending = $this->getSettingForCorporation('tax_rates.tax_calculation_period_pending', $corporationId);
        $effectiveFrom = $this->getSettingForCorporation('tax_rates.tax_calculation_period_effective_from', $corporationId);

        if ($requested === $current) {
            $write('tax_rates.tax_calculation_period_pending', null);
            $write('tax_rates.tax_calculation_period_effective_from', null);

            return null;
        }

        if ($applyNow && $periods->canChangePeriodToday($corporationId)) {
            $write('tax_rates.tax_calculation_period', $requested);
            $write('tax_rates.tax_calculation_period_pending', null);
            $write('tax_rates.tax_calculation_period_effective_from', null);

            return 'today';
        }

        // Asking again for a change already queued keeps its date. Queued
        // afresh on the day it takes effect, before anything has read the
        // period, it would wait another month.
        if ($requested !== $pending || !$effectiveFrom) {
            $write('tax_rates.tax_calculation_period_pending', $requested);
            $write('tax_rates.tax_calculation_period_effective_from', $periods->nextSafeEffectiveDate()->toDateString());
        }

        return 'queued';
    }

    /**
     * Give a corporation configured part way through a month the billing
     * period its members were on until now, Global's, with the one its
     * profile came with queued like any change of period, unless the change
     * can be made today. Taking the new period at once could leave part of
     * the month billed twice or not at all for them.
     */
    private function startOnMembersPeriod(int $corporationId): void
    {
        $periods = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
        $read = fn (string $key, $default = null) => $this->getSettingForCorporation($key, $corporationId, $default);
        $write = fn (string $key, $value) => $this->updateSettingForCorporation($key, $value, 'string', $corporationId);

        $active = (string) $read('tax_rates.tax_calculation_period', 'monthly');
        $target = $read('tax_rates.tax_calculation_period_pending') ?: $active;

        if (!$periods->canChangePeriodToday(null)) {
            $active = $periods->periodTypeFor(null);
        }

        $write('tax_rates.tax_calculation_period', $active);
        $write('tax_rates.tax_calculation_period_pending', $target === $active ? null : $target);
        $write('tax_rates.tax_calculation_period_effective_from', $target === $active ? null : $periods->nextSafeEffectiveDate()->toDateString());
    }

    /**
     * Save one tax profile from the Tax profiles page: Global when the
     * corporation is null, else that configured corporation's own.
     *
     * @param array{prefix?: string, moon?: string, rates?: array<string, mixed>, taxed?: array<string, mixed>, period?: string} $values
     * @return string|null what became of the billing period, as setPeriodFor() says
     */
    public function saveProfile(?int $corporationId, array $values, bool $applyPeriodNow): ?string
    {
        $write = fn (string $key, $value, string $type) => $this->updateSettingForCorporation($key, $value, $type, $corporationId);

        if (isset($values['prefix'])) {
            $write('tax_rates.tax_code_prefix', $values['prefix'], 'string');
        }

        if (isset($values['moon'])) {
            $write('tax_selector.all_moon_ore', $values['moon'] === 'all', 'boolean');
            $write('tax_selector.only_corp_moon_ore', $values['moon'] === 'ours', 'boolean');
            $write('tax_selector.no_moon_ore', $values['moon'] === 'none', 'boolean');
        }

        foreach ($values['rates'] ?? [] as $key => $rate) {
            $write("tax_rates.{$key}", (float) $rate, 'float');
        }

        foreach ($values['taxed'] ?? [] as $key => $taxed) {
            $write("tax_selector.{$key}", (bool) $taxed, 'boolean');
        }

        return isset($values['period']) ? $this->setPeriodFor($corporationId, $values['period'], $applyPeriodNow) : null;
    }

    /**
     * Configure a corporation: put it on the list with a profile of its own,
     * a copy of another profile (Global when none is named), so it starts
     * out taxed as that one is rather than at the defaults.
     */
    public function configureCorporation(int $corporationId, ?int $copyFrom): void
    {
        foreach (SettingsRegistry::keys(SettingsRegistry::PROFILE) as $key) {
            $query = Setting::where('key', $key);
            $copyFrom ? $query->where('corporation_id', $copyFrom) : $query->whereNull('corporation_id');
            $source = $query->first();

            if ($source === null) {
                continue;
            }

            Cache::forget(self::CACHE_PREFIX . $corporationId . '_' . $key);
            Setting::updateOrCreate(
                ['key' => $key, 'corporation_id' => $corporationId],
                ['value' => $source->value, 'type' => $source->type]
            );
            Cache::forget(self::CACHE_PREFIX . $corporationId . '_' . $key);
        }

        $this->addConfiguredCorporation($corporationId);
        $this->startOnMembersPeriod($corporationId);
    }

    /**
     * Take a corporation off the configured list. From then on its members
     * are non-members, taxed by Global. Its profile stays where it is, so
     * nothing is lost if it is added back, though adding it copies afresh.
     * The Moon Owner is always configured and cannot be taken off.
     */
    public function removeConfiguredCorporation(int $corporationId): void
    {
        $ids = array_map('intval', (array) $this->getSettingForCorporation(SettingsTransfer::CONFIGURED_KEY, null, []));

        $this->updateGlobalSetting(SettingsTransfer::CONFIGURED_KEY, array_values(array_diff($ids, [$corporationId])), 'json');
    }

    /**
     * Get tax selector settings
     * UPDATED: Added no_moon_ore flag for completeness
     *
     * @return array
     */
    public function getTaxSelector(): array
    {
        return $this->taxSelectorFor($this->activeCorporationId);
    }

    /**
     * What the profile that taxes a corporation's members taxes, read like
     * taxRatesFor().
     *
     * @return array<string, mixed>
     */
    public function taxSelectorFor(?int $corporationId): array
    {
        $taxes = fn (string $key, bool $default) => $this->getSettingForCorporation("tax_selector.{$key}", $corporationId, $default);

        return [
            // Moon ore selector (mutually exclusive)
            'all_moon_ore' => $taxes('all_moon_ore', true),
            'only_corp_moon_ore' => $taxes('only_corp_moon_ore', false),
            'no_moon_ore' => $taxes('no_moon_ore', false),

            // Other ore types (independent checkboxes)
            'ore' => $taxes('ore', true),
            'ice' => $taxes('ice', true),
            'gas' => $taxes('gas', false),
            'abyssal_ore' => $taxes('abyssal_ore', false),
            'triglavian_ore' => $taxes('triglavian_ore', false),
        ];
    }

    /**
     * What a set of tax profiles tax between them, as one selector: every
     * category any of them taxes, and the widest moon ore choice among them.
     * For work that covers several profiles' members at once, such as an
     * event open to everyone, where each miner is still charged by their own
     * profile.
     *
     * Global's ore, ice and gas count only while no corporation is
     * configured: with corporations configured, non-members' own mining is
     * never taxed.
     *
     * @param array<int, int|null> $profiles
     * @return array<string, bool>
     */
    public function taxSelectorAcross(array $profiles): array
    {
        $configured = $this->usesTaxProfiles() && $this->getHomeCorporationIds();
        $moon = 'none';
        $others = ['ore' => false, 'ice' => false, 'gas' => false, 'abyssal_ore' => false, 'triglavian_ore' => false];

        foreach ($profiles as $profile) {
            $selector = $this->taxSelectorFor($profile);

            // Decided as the tax calculation decides it: no moon ore wins,
            // then only the Moon Owner's.
            $choice = !empty($selector['no_moon_ore']) ? 'none'
                : (!empty($selector['only_corp_moon_ore']) ? 'ours' : (($selector['all_moon_ore'] ?? true) ? 'all' : 'none'));
            if ($choice === 'all' || ($choice === 'ours' && $moon === 'none')) {
                $moon = $choice;
            }

            if ($profile === null && $configured) {
                continue;
            }

            foreach ($others as $key => $taxed) {
                $others[$key] = $taxed || !empty($selector[$key]);
            }
        }

        return [
            'all_moon_ore' => $moon === 'all',
            'only_corp_moon_ore' => $moon === 'ours',
            'no_moon_ore' => $moon === 'none',
        ] + $others;
    }

    /**
     * Update tax selector
     *
     * @param array $selector
     * @return void
     */
    public function updateTaxSelector(array $selector)
    {
        DB::beginTransaction();
        
        try {
            foreach ($selector as $key => $value) {
                $this->updateSetting("tax_selector.{$key}", $value, 'boolean');
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get exemptions
     *
     * @return array
     */
    public function getExemptions(): array
    {
        return [
            'enabled' => $this->getSetting('exemptions.enabled', false),
            'threshold' => $this->getSetting('exemptions.threshold', 1000000),  // 1M ISK default
            'grace_period_days' => $this->getSetting('exemptions.grace_period_days', 7),
        ];
    }

    /**
     * Update exemptions
     *
     * @param array $exemptions
     * @return void
     */
    public function updateExemptions(array $exemptions)
    {
        DB::beginTransaction();
        
        try {
            foreach ($exemptions as $key => $value) {
                $this->updateSetting("exemptions.{$key}", $value);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get pricing settings
     *
     * @return array
     */
    public function getPricingSettings(): array
    {
        return [
            // Price provider (checks settings first, falls back to ENV)
            'price_provider' => $this->getSetting('price_provider', config('mining-manager.general.price_provider', 'seat')),
            'price_type' => $this->getSetting('pricing.price_type', 'sell'),
            'cache_duration' => $this->getSetting('pricing.cache_duration', 240),
            'fallback_to_jita' => $this->getSetting('pricing.fallback_to_jita', true),

            // Asked when the price provider fails outright; 'none' keeps the
            // cached prices instead.
            'fallback_provider' => $this->getSetting('pricing.fallback_provider', 'fuzzwork'),

            // Janice settings (checks settings first, then falls back to ENV)
            'janice_api_key' => $this->getSetting('janice_api_key')
                ?: config('mining-manager.general.price_provider_api_key', ''),
            'janice_market' => $this->getSetting('janice_market', 'jita'),
            'janice_price_method' => $this->getSetting('janice_price_method', 'buy'),

            // Manager Core settings.
            //
            // manager_core_market: source of truth is MC's per-plugin
            // pricing preference (manager_core_pricing_preferences table).
            // We pull it via the pricing.getPreferenceForPlugin bridge
            // capability so the operator's MC-side change in the Pricing
            // Preferences UI immediately propagates to MM's reads — no
            // restart, no double-config-write, no drift between MM's
            // local copy and MC's authoritative row.
            //
            // Fallback chain (in order):
            //   1. MC bridge call returns a pref row → use pref['market']
            //   2. MC bridge call fails (older MC, bridge boot failure) →
            //      'jita' as last-resort literal
            //
            // Note: the local 'pricing.manager_core_market' row was dropped
            // from mining_manager_settings in migration 000018. Even on
            // installs that hadn't run the migration yet, the row is
            // unused — this method is the only reader and it now consults
            // MC's pref first.
            //
            // manager_core_variant: hardcoded to 'min' because that's the
            // only variant that produces meaningful tax + payout values
            // (lowest sell = real buy price). Operator-selectable variant
            // was removed from MM's UI in commit 583ea48.
            'manager_core_market' => $this->resolveMcMarket(),
            'manager_core_variant' => 'min',

            // Refining settings
            'refining_efficiency' => $this->getSetting('pricing.refining_efficiency', 87.5),
        ];
    }

    /**
     * Resolve the market MM should read prices at, when the price provider
     * is set to Manager Core.
     *
     * Single point of bridge integration so every consumer of
     * getPricingSettings()['manager_core_market'] transparently sees the
     * operator-configured market without each one calling the bridge
     * independently.
     *
     * Cached for the request (one bridge call max per HTTP request) since
     * getPricingSettings() can be invoked many times during a single page
     * render (Dashboard / Diagnostic / Moon views all touch it). The
     * pricing.preference_changed EventBus subscription flushes downstream
     * caches when the operator saves a change in MC; this in-request
     * memo is just to avoid hammering the bridge.
     *
     * @return string Market key (e.g. 'jita', 'c-j6mt'). Falls back to
     *                'jita' if MC's bridge call fails for any reason
     *                (older MC version without the capability, bridge
     *                boot failure, no preference row registered yet).
     */
    protected function resolveMcMarket(): string
    {
        // Static cache for the request — avoids N bridge calls per page
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $market = 'jita';

        try {
            if (class_exists('ManagerCore\\Services\\PluginBridge')) {
                $bridge = app(\ManagerCore\Services\PluginBridge::class);
                $pref = $bridge->call('ManagerCore', 'pricing.getPreferenceForPlugin', 'mining-manager');
                if (is_array($pref) && !empty($pref['market'])) {
                    $market = (string) $pref['market'];
                }
            }
        } catch (\Throwable $e) {
            // Bridge call failed — keep the 'jita' literal. No log spam;
            // this is expected on older MC versions without the capability.
        }

        $cached = $market;
        return $cached;
    }

    /**
     * Update pricing settings
     *
     * @param array $settings
     * @return void
     */
    public function updatePricingSettings(array $settings)
    {
        DB::beginTransaction();
        
        try {
            foreach ($settings as $key => $value) {
                $this->updateSetting('pricing.' . $key, $value);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get event settings
     *
     * @return array
     */
    public function getEventSettings(): array
    {
        return config('mining-manager.events', []);
    }

    /**
     * Get moon settings
     *
     * @return array
     */
    public function getMoonSettings(): array
    {
        return [
            'estimated_chunk_size' => $this->getSetting('moon.estimated_chunk_size', config('mining-manager.moon.estimated_chunk_size', 150000)),
        ];
    }

    /**
     * Get report settings
     *
     * @return array
     */
    public function getReportSettings(): array
    {
        return config('mining-manager.reports', []);
    }

    /**
     * The types with a card on Settings > Notifications, which is where a
     * type's master switch and role ping are set. Saving that page writes
     * these types only. A type with no card, such as the outstanding tax
     * digest, keeps what is stored for it, or the defaults: on, no role ping.
     */
    public const NOTIFICATION_TYPES = [
        // Tax
        'tax_generated', 'tax_announcement', 'tax_reminder', 'tax_invoice', 'tax_overdue', 'tax_outstanding_digest',
        // Events
        'event_created', 'event_started', 'event_completed',
        // Moon
        'moon_ready', 'jackpot_detected', 'moon_chunk_unstable',
        // Moon, from Structure Manager's alerts through Manager Core
        'extraction_at_risk', 'extraction_lost',
        // Planner — extraction lifecycle coordination
        'extraction_started', 'next_extraction_planned', 'schedule_mismatch',
        'refinery_gone', 'extraction_cancelled', 'moon_not_rescheduled', 'schedule_needs_filling',
        // Metenox (v2.0.1) — bay-full warning, standalone (no cross-plugin deps)
        'metenox_cargo_full',
        // Plugin health
        'price_provider', 'moon_scan_missing',
        // Theft
        'theft_detected', 'critical_theft', 'active_theft', 'incident_resolved',
        // Reports
        'report_generated',
    ];

    /**
     * The types with a box in the Slack list on the same page. A list of its
     * own, as a type can have a Slack box without a card or the other way
     * round, and a box missing from its list would save as off.
     */
    public const SLACK_NOTIFICATION_TYPES = [
        // Tax
        'tax_generated', 'tax_announcement', 'tax_reminder', 'tax_invoice', 'tax_overdue',
        // Events
        'event_created', 'event_started', 'event_completed',
        // Moon
        'moon_ready', 'jackpot_detected', 'moon_chunk_unstable', 'extraction_started',
        'extraction_at_risk', 'extraction_lost', 'metenox_cargo_full',
        // Planner
        'next_extraction_planned', 'schedule_mismatch', 'refinery_gone', 'extraction_cancelled',
        'moon_not_rescheduled', 'schedule_needs_filling',
        'tax_outstanding_digest',
        // Plugin health
        'price_provider', 'moon_scan_missing',
        // Theft
        'theft_detected', 'critical_theft', 'active_theft', 'incident_resolved',
        // Reports
        'report_generated',
    ];

    /**
     * Get notification settings
     *
     * @return array
     */
    public function getNotificationSettings(): array
    {
        $allTypes = self::NOTIFICATION_TYPES;

        // Build default enabled_types (all true)
        $defaultEnabled = array_fill_keys($allTypes, true);

        return [
            // Global per-type toggles (master switches)
            'enabled_types' => $this->getSetting('notifications.enabled_types', $defaultEnabled),

            // Per-type settings (role ping, user ping, show amount)
            'type_settings' => $this->getSetting('notifications.type_settings', []),

            // EVE Mail
            'evemail_enabled' => (bool) $this->getSetting('notifications.evemail_enabled', false),
            'evemail_sender_character_id' => $this->getSetting('notifications.evemail_sender_character_id', null),
            'evemail_sender_character_override' => $this->getSetting('notifications.evemail_sender_character_override', null),
            'evemail_types' => $this->getSetting('notifications.evemail_types', $defaultEnabled),

            // Slack
            'slack_enabled' => (bool) $this->getSetting('notifications.slack_enabled', false),
            'slack_webhook_url' => $this->getSetting('notifications.slack_webhook_url', ''),
            'slack_types' => $this->getSetting('notifications.slack_types', $defaultEnabled),
            // Legacy global Slack health metrics. Per-webhook rows in
            // webhook_configurations track success/failure/last_error on
            // the model itself; the legacy global path (single URL stored
            // in `notifications.slack_webhook_url`) had no equivalent
            // until the M7 fix wired up persistent counters via
            // recordLegacySlackSuccess / recordLegacySlackFailure in
            // NotificationService::sendViaSlack.
            'slack_legacy_success_count' => (int) $this->getSetting('notifications.slack_legacy_success_count', 0),
            'slack_legacy_failure_count' => (int) $this->getSetting('notifications.slack_legacy_failure_count', 0),
            'slack_legacy_last_success_at' => $this->getSetting('notifications.slack_legacy_last_success_at', null),
            'slack_legacy_last_failure_at' => $this->getSetting('notifications.slack_legacy_last_failure_at', null),
            'slack_legacy_last_error' => $this->getSetting('notifications.slack_legacy_last_error', ''),

            // Metenox cargo-full notification threshold (default 85%, clamped
            // [50, 99] both client-side via the UI input + server-side in the
            // scanner). Read here so the settings view can prefill the input.
            'metenox_cargo_full_threshold_pct' => (float) $this->getSetting('notifications.metenox_cargo_full_threshold_pct', 85),

            // Moon Extraction Planner — minimum gap (hours) between two chunk
            // arrivals before the planner warns about clustering.
            'min_extraction_gap_hours' => (int) $this->getSetting('notifications.min_extraction_gap_hours', 24),

            // Moon Extraction Planner: how close two pulls on one refinery may
            // be before the planner warns, in hours. The form shows days.
            'min_refinery_spacing_hours' => (int) $this->getSetting(
                'notifications.min_refinery_spacing_hours',
                \MiningManager\Services\Moon\MoonPlannerService::DEFAULT_REFINERY_SPACING_HOURS
            ),

            // Moon Not Rescheduled: idle hours before the reminder, and whether
            // it repeats every that many hours until an extraction starts.
            'moon_not_rescheduled_hours' => (int) $this->getSetting('notifications.moon_not_rescheduled_hours', 48),
            'moon_not_rescheduled_repeat' => (bool) $this->getSetting('notifications.moon_not_rescheduled_repeat', true),

            // Moons Need Planning: pulls each refinery should have planned
            // ahead, and hours between lists while some fall short.
            'planned_ahead_target' => (int) $this->getSetting('notifications.planned_ahead_target', 1),
            'schedule_needs_filling_hours' => (int) $this->getSetting('notifications.schedule_needs_filling_hours', 24),

            // Moon Scan Missing: list every moon still missing a scan again
            // once a day, on top of the message for each new reason.
            'moon_scan_missing_daily' => (bool) $this->getSetting('notifications.moon_scan_missing_daily', false),

            // extraction_started detection mode. 'auto' uses Manager Core's
            // ESI fast-poll when present (~2 min detection); 'seat_native'
            // forces the slower endpoint-driven cron pass even with MC.
            'moon_extraction_fastpoll_mode' => $this->getSetting('notifications.moon_extraction_fastpoll_mode', 'auto'),

            // seat-connector availability (runtime)
            'seat_connector_available' => \Illuminate\Support\Facades\Schema::hasTable('seat_connector_users'),
        ];
    }

    /**
     * Get per-type notification settings for a specific type
     *
     * @param string $type Notification type key (e.g., 'tax_reminder')
     * @return array ['ping_role' => bool, 'role_id' => string|null, 'ping_user' => bool, 'show_amount' => bool]
     */
    public function getTypeNotificationSettings(string $type): array
    {
        $allTypeSettings = $this->getSetting('notifications.type_settings', []);
        $typeSettings = $allTypeSettings[$type] ?? [];

        return [
            'ping_role' => (bool) ($typeSettings['ping_role'] ?? false),
            'role_id' => $typeSettings['role_id'] ?? null,
            'ping_user' => (bool) ($typeSettings['ping_user'] ?? false),
            'show_amount' => (bool) ($typeSettings['show_amount'] ?? true),
            // Only the corporations picked on the card; none picked means all.
            'corporations' => array_values(array_filter(array_map('intval', (array) ($typeSettings['corporations'] ?? [])))),
        ];
    }

    /**
     * Update notification settings
     *
     * @param array $settings
     * @return void
     */
    public function updateNotificationSettings(array $settings): void
    {
        DB::beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                $this->updateSetting('notifications.' . $key, $value);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get characters that have the esi-mail.send_mail.v1 scope
     *
     * @return \Illuminate\Support\Collection
     */
    public function getMailScopeCharacters(): \Illuminate\Support\Collection
    {
        return DB::table('refresh_tokens')
            ->join('character_infos', 'refresh_tokens.character_id', '=', 'character_infos.character_id')
            ->where('refresh_tokens.scopes', 'LIKE', '%esi-mail.send_mail.v1%')
            ->select('refresh_tokens.character_id', 'character_infos.name')
            ->orderBy('character_infos.name')
            ->get();
    }

    /**
     * Get all characters with refresh tokens (regardless of scopes).
     * Includes a has_mail_scope flag to indicate if the character can send EVE mail.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllTokenCharacters(): \Illuminate\Support\Collection
    {
        return DB::table('refresh_tokens')
            ->join('character_infos', 'refresh_tokens.character_id', '=', 'character_infos.character_id')
            ->select(
                'refresh_tokens.character_id',
                'character_infos.name',
                DB::raw("CASE WHEN refresh_tokens.scopes LIKE '%esi-mail.send_mail.v1%' THEN 1 ELSE 0 END as has_mail_scope")
            )
            ->orderBy('character_infos.name')
            ->get();
    }

    /**
     * Get dashboard settings
     *
     * @return array
     */
    public function getDashboardSettings(): array
    {
        $configDefaults = config('mining-manager.dashboard', []);

        // Install-wide: read the way the dashboard reads them, whatever
        // corporation the settings page is set to.
        return array_merge($configDefaults, [
            'dashboard_leaderboard_corporation_filter' => $this->getSettingForCorporation('dashboard_leaderboard_corporation_filter', null, 'all'),
            'dashboard_leaderboard_corporation_ids' => $this->getSettingForCorporation('dashboard_leaderboard_corporation_ids', null, '[]'),
        ]);
    }

    /**
     * Get feature flags
     *
     * @return array
     */
    public function getFeatureFlags(): array
    {
        return [
            // Core features
            'enable_tax_tracking' => (bool) $this->getSetting('features.enable_tax_tracking', true),
            'enable_ledger_tracking' => (bool) $this->getSetting('features.enable_ledger_tracking', true),
            'enable_analytics' => (bool) $this->getSetting('features.enable_analytics', true),
            'enable_reports' => (bool) $this->getSetting('features.enable_reports', true),

            // Events
            'enable_events' => (bool) $this->getSetting('features.enable_events', true),
            'allow_event_creation' => (bool) $this->getSetting('features.allow_event_creation', true),

            // Moon mining
            'enable_moon_tracking' => (bool) $this->getSetting('features.enable_moon_tracking', true),

            // Theft detection. Global, as the scan runs once for the moon owner.
            'allow_guest_mining' => (bool) $this->getSettingForCorporation('features.allow_guest_mining', null, true),
            'theft_overdue_days' => (int) $this->getSettingForCorporation('features.theft_overdue_days', null, 15),

            // Permissions & access
            'allow_member_leaderboard' => (bool) $this->getSetting('features.allow_member_leaderboard', true),
            'show_character_names' => (bool) $this->getSetting('features.show_character_names', true),
            'allow_export_data' => (bool) $this->getSetting('features.allow_export_data', true),

            // Automation
            'auto_process_ledger' => (bool) $this->getSetting('features.auto_process_ledger', true),
            'auto_calculate_taxes' => (bool) $this->getSetting('features.auto_calculate_taxes', true),
            'auto_generate_invoices' => (bool) $this->getSetting('features.auto_generate_invoices', true),
            'verify_wallet_transactions' => (bool) $this->getSetting('features.verify_wallet_transactions', true),
            'enable_upfront_payments' => (bool) $this->getSettingForCorporation('features.enable_upfront_payments', null, false),

            // Data retention
            'ledger_retention_days' => (int) $this->getSetting('features.ledger_retention_days', 365),
            'auto_cleanup_old_data' => (bool) $this->getSetting('features.auto_cleanup_old_data', false),
        ];
    }

    /**
     * Update feature flags
     *
     * @param array $features
     * @return void
     */
    public function updateFeatureFlags(array $features)
    {
        DB::beginTransaction();

        try {
            foreach ($features as $key => $value) {
                $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');

                // Upfront payments is global, for the same reason the keyword
                // is. The wallet matcher runs as one corporation, the moon
                // owner, so a flag saved against any other corporation is never
                // consulted: the box would tick and nothing would change. The
                // theft settings are global for the same reason: one scan, run
                // for the moon owner.
                if (in_array($key, ['enable_upfront_payments', 'allow_guest_mining', 'theft_overdue_days'], true)) {
                    $savedContext = $this->activeCorporationId;
                    $this->activeCorporationId = null;
                    $this->updateSetting("features.{$key}", $value, $type);
                    $this->activeCorporationId = $savedContext;

                    continue;
                }

                $this->updateSetting("features.{$key}", $value, $type);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reset all settings to config defaults
     *
     * @return void
     */
    public function resetToDefaults()
    {
        DB::beginTransaction();

        try {
            // The plugin's own records are not settings: cutover dates, the
            // record of the move into tax profiles, when an alert last went
            // out. Losing them would reopen questions the plugin has already
            // settled, such as which bills follow the old rules, so a reset
            // leaves them alone.
            $records = SettingsRegistry::keys(SettingsRegistry::STATE);

            $settingKeys = Setting::whereNotIn('key', $records)->pluck('key')->unique()->toArray();
            $corporationIds = Setting::whereNotNull('corporation_id')
                ->distinct()
                ->pluck('corporation_id')
                ->toArray();

            Setting::whereNotIn('key', $records)->delete();
            $this->homeCorporations = null;

            // Clear only mining-manager cache keys (not the entire application cache)
            foreach ($settingKeys as $key) {
                Cache::forget(self::CACHE_PREFIX . 'global_' . $key);
                foreach ($corporationIds as $corpId) {
                    Cache::forget(self::CACHE_PREFIX . $corpId . '_' . $key);
                }
            }

            // Also try tag-based flush if cache driver supports it
            try {
                Cache::tags(['mining-manager'])->flush();
            } catch (\Exception $cacheException) {
                // File/database cache driver doesn't support tags - acceptable
            }

            Log::info('Mining Manager: All settings reset to defaults');

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Export all settings as array
     *
     * @return array
     */
    /**
     * Export settings for moving a configuration between installs.
     *
     * Every row carries its own corporation_id. A flat key => value map cannot
     * represent this data: most keys exist several times over, once globally and
     * once per configured corporation, and flattening them silently keeps
     * whichever row the database happened to return last.
     *
     * Webhooks are opt-in because webhook_url is a credential. Anyone holding
     * the exported file can post to those channels, so putting them in by
     * default would turn a settings backup into a secret people email around.
     *
     * @param bool $includeWebhooks
     * @return array
     */
    public function exportSettings(bool $includeWebhooks = false): array
    {
        $profiles = $this->usesTaxProfiles();

        $settings = Setting::query()
            ->orderBy('corporation_id')
            ->orderBy('key')
            ->get()
            // The plugin's own records, such as cutover dates, belong to the
            // install that wrote them, and a copy of an install-wide setting
            // saved under a corporation is one nothing reads.
            ->reject(fn ($setting) => $profiles && (
                SettingsRegistry::scope($setting->key) === SettingsRegistry::STATE
                || (SettingsRegistry::scope($setting->key) === SettingsRegistry::INSTALL && $setting->corporation_id !== null)
            ))
            ->map(function ($setting) {
                return [
                    'key' => $setting->key,
                    'value' => $setting->value,
                    'type' => $setting->type,
                    'corporation_id' => $setting->corporation_id,
                    'description' => $setting->description,
                ];
            })
            ->values()
            ->all();

        $export = [
            'exported_at' => now()->toDateTimeString(),
            'version' => config('mining-manager.version', '1.0.0'),
            // 3: settings in tax profiles. The same keys mean different things
            // in a file from before them, so the two do not mix.
            'format' => $profiles ? 3 : 2,
            'settings' => $settings,
        ];

        if ($includeWebhooks) {
            $export['webhooks'] = WebhookConfiguration::query()
                ->orderBy('corporation_id')
                ->orderBy('name')
                ->get()
                ->map(function ($hook) {
                    $row = $hook->toArray();

                    // Delivery statistics belong to the install that produced
                    // them, not to the configuration being moved.
                    foreach ([
                        'id', 'success_count', 'failure_count',
                        'last_success_at', 'last_failure_at', 'last_error',
                        'created_at', 'updated_at',
                    ] as $drop) {
                        unset($row[$drop]);
                    }

                    return $row;
                })
                ->values()
                ->all();
        }

        return $export;
    }

    /**
     * Import a settings export.
     *
     * Understands both shapes: the current one, where settings are rows that
     * each name their own corporation, and the original flat key => value map,
     * which had no corporation information at all and is therefore treated as
     * global. Once settings are in tax profiles, only files exported from them
     * are taken.
     *
     * Additive. Nothing is deleted, so importing a partial file tops up a
     * configuration rather than replacing it. Billing periods change as they
     * do on the Tax profiles page: today when that is safe, otherwise from
     * the next safe day, listed under 'periods'.
     *
     * @param array $data
     * @return array{settings:int,webhooks:int,skipped:int,legacy:bool,periods:array<int, array{profile: int|null, from: \Carbon\Carbon}>}
     */
    public function importSettings(array $data): array
    {
        if (!isset($data['settings'])) {
            throw new \Exception('Invalid settings format');
        }

        $rows = $data['settings'];
        $legacy = !array_is_list($rows);
        $profiles = $this->usesTaxProfiles();

        // In a file from before tax profiles the global moon rates are what
        // members paid. Here they are what non-members pay, so importing one
        // would quietly charge the wrong people.
        if ($profiles && ($legacy || (int) ($data['format'] ?? 0) < 3)) {
            throw new \Exception('This file was exported before settings moved into tax profiles, so its values would land in the wrong places. Export again from an install on this version, or set the profiles by hand.');
        }

        $imported = 0;
        $skipped = 0;
        $webhooks = 0;
        $savedContext = $this->activeCorporationId;
        $configuredBefore = $profiles ? $this->getHomeCorporationIds() : [];
        $periodRows = [];

        DB::beginTransaction();

        try {
            if ($legacy) {
                // Original format: a flat map with no corporation context, so
                // everything lands globally, which is where it came from.
                $this->activeCorporationId = null;

                foreach ($rows as $key => $value) {
                    $this->updateSetting($key, $value);
                    $imported++;
                }
            } else {
                foreach ($rows as $row) {
                    if (!is_array($row) || !isset($row['key'])) {
                        $skipped++;
                        continue;
                    }

                    if ($profiles) {
                        $scope = SettingsRegistry::scope($row['key']);

                        if ($scope === SettingsRegistry::STATE
                            || ($scope === SettingsRegistry::INSTALL && !empty($row['corporation_id']))) {
                            $skipped++;
                            continue;
                        }

                        // Adds to the corporations configured here rather
                        // than replacing them, like the rest of an import.
                        if ($row['key'] === SettingsTransfer::CONFIGURED_KEY) {
                            $list = is_array($row['value'] ?? null) ? $row['value'] : json_decode((string) ($row['value'] ?? ''), true);
                            foreach ((array) $list as $corporationId) {
                                if ((int) $corporationId > 0) {
                                    $this->addConfiguredCorporation((int) $corporationId);
                                }
                            }

                            $imported++;
                            continue;
                        }

                        // A corporation with a profile in the file is a
                        // configured one, here as it was there.
                        if ($scope === SettingsRegistry::PROFILE && !empty($row['corporation_id'])) {
                            $this->addConfiguredCorporation((int) $row['corporation_id']);
                        }

                        // A billing period this install already has changes
                        // below, the way a change on the Tax profiles page does.
                        $profile = empty($row['corporation_id']) ? null : (int) $row['corporation_id'];
                        if (in_array($row['key'], self::PERIOD_KEYS, true) && ($profile === null || in_array($profile, $configuredBefore, true))) {
                            $periodRows[$profile ?? 'global'][$row['key']] = $row['value'] ?? null;
                            $imported++;
                            continue;
                        }
                    }

                    // Write in the row's own context, not the caller's.
                    $this->activeCorporationId = isset($row['corporation_id'])
                        ? ($row['corporation_id'] === null ? null : (int) $row['corporation_id'])
                        : null;

                    $value = $row['value'] ?? null;
                    $type = $row['type'] ?? null;

                    // The export carries a json value as the text it was stored
                    // as, and saving that text would encode it a second time.
                    // Decoded without assoc, so {} and an object keyed 0..n
                    // come back exactly as they were stored.
                    if (in_array($type, ['json', 'array'], true) && is_string($value)) {
                        $decoded = json_decode($value);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $decoded;
                        }
                    }

                    $this->updateSetting($row['key'], $value, $type);

                    $imported++;
                }
            }

            // A billing period changes today only when that is safe, so an
            // import part way through a month cannot leave part of it billed
            // twice or not at all. A change queued in the file is queued again
            // from this install's next safe day, not the file's.
            $this->activeCorporationId = null;
            foreach ($periodRows as $profile => $period) {
                $profile = $profile === 'global' ? null : (int) $profile;
                $active = $period['tax_rates.tax_calculation_period'] ?? null;
                $pending = $period['tax_rates.tax_calculation_period_pending'] ?? null;

                if (in_array($active, ['monthly', 'biweekly', 'weekly'], true)) {
                    $this->setPeriodFor($profile, $active, true);
                }

                if (in_array($pending, ['monthly', 'biweekly', 'weekly'], true) && !empty($period['tax_rates.tax_calculation_period_effective_from'])) {
                    $this->setPeriodFor($profile, $pending, false);
                }
            }

            // A corporation the file configures starts on the period its
            // members were on, as one added on the Setup page does.
            $added = $profiles ? array_diff($this->getHomeCorporationIds(), $configuredBefore) : [];
            foreach ($added as $corporationId) {
                $this->startOnMembersPeriod($corporationId);
            }

            if (!empty($data['webhooks']) && is_array($data['webhooks'])) {
                foreach ($data['webhooks'] as $hook) {
                    if (!is_array($hook) || empty($hook['name'])) {
                        $skipped++;
                        continue;
                    }

                    // Name plus corporation identifies a webhook well enough to
                    // re-import over itself without piling up duplicates.
                    WebhookConfiguration::updateOrCreate(
                        [
                            'name' => $hook['name'],
                            'corporation_id' => $hook['corporation_id'] ?? null,
                        ],
                        $hook
                    );

                    $webhooks++;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->activeCorporationId = $savedContext;
            throw $e;
        }

        $this->activeCorporationId = $savedContext;

        // updateSetting() drops each key's own cache entry as it writes. The
        // wider flush (aggregate getters, tab visibility) belongs to the caller,
        // which knows whether it is finishing a request.
        Log::info('Mining Manager: settings imported', [
            'settings' => $imported,
            'webhooks' => $webhooks,
            'skipped' => $skipped,
            'legacy_format' => $legacy,
        ]);

        // The profiles whose billing period from the file is still to come.
        $periods = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
        $waiting = [];
        foreach (array_merge(array_keys($periodRows), $added) as $profile) {
            $profile = $profile === 'global' ? null : (int) $profile;
            if ($change = $periods->pendingPeriodChangeFor($profile)) {
                $waiting[] = ['profile' => $profile, 'from' => $change['effective_from']];
            }
        }

        return [
            'settings' => $imported,
            'webhooks' => $webhooks,
            'skipped' => $skipped,
            'legacy' => $legacy,
            'periods' => $waiting,
        ];
    }

    /**
     * The corporations whose members are members here: the configured
     * corporations, and always the Moon Owner. Everyone else is a non-member,
     * taxed by the Global profile.
     *
     * Once settings are in tax profiles the list is a setting of its own, so a
     * corporation joins or leaves it only when someone adds or removes it.
     * Before that, any corporation with a settings row counted, even one that
     * only had a stray row written by a background job, and that is the list
     * the transfer copied.
     *
     * @return array<int>
     */
    public function getHomeCorporationIds(): array
    {
        if ($this->homeCorporations !== null && $this->homeCorporations[0] > microtime(true)) {
            return $this->homeCorporations[1];
        }

        if ($this->usesTaxProfiles()) {
            $configuredCorpIds = array_map('intval', (array) $this->getSettingForCorporation(SettingsTransfer::CONFIGURED_KEY, null, []));
        } else {
            $configuredCorpIds = DB::table('mining_manager_settings')
                ->whereNotNull('corporation_id')
                ->distinct()
                ->pluck('corporation_id')
                ->map(fn($id) => (int) $id)
                ->toArray();
        }

        $moonOwnerCorpId = (int) $this->getSettingForCorporation('general.moon_owner_corporation_id', null);
        if ($moonOwnerCorpId) {
            $configuredCorpIds[] = $moonOwnerCorpId;
        }

        $configuredCorpIds = array_values(array_unique($configuredCorpIds));
        $this->homeCorporations = [microtime(true) + 5, $configuredCorpIds];

        return $configuredCorpIds;
    }

    /**
     * The tax profile for a corporation's members: its own when it is
     * configured, Global (null) when it is not. A pilot whose corporation is
     * not known yet counts as the Moon Owner's, rather than being charged the
     * non-members' rates on the strength of a lookup that has not finished.
     * With nothing configured at all there is only Global.
     */
    public function profileFor(?int $corporationId): ?int
    {
        $home = $this->getHomeCorporationIds();

        if (!$home) {
            return null;
        }

        if ($corporationId === null) {
            return (int) $this->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        }

        return in_array($corporationId, $home, true) ? $corporationId : null;
    }

    /**
     * Every tax profile: Global first, as null, then each configured
     * corporation's.
     *
     * @return array<int, int|null>
     */
    public function taxProfiles(): array
    {
        return array_merge([null], $this->usesTaxProfiles() ? $this->getHomeCorporationIds() : []);
    }

    /**
     * Every prefix a payment code can be made with. One per profile once
     * settings are in tax profiles, so anything else matched against the
     * same transfer reason has to stay clear of all of them.
     *
     * @return array<int, string>
     */
    public function taxCodePrefixes(): array
    {
        $prefixes = array_map(
            fn (?int $profile) => trim((string) $this->getSettingForCorporation('tax_rates.tax_code_prefix', $profile, 'TAX-')),
            $this->taxProfiles()
        );

        return array_values(array_unique(array_filter($prefixes)));
    }

    /**
     * Whether a corporation's members are members here.
     */
    public function isConfiguredCorporation(int $corporationId): bool
    {
        return in_array($corporationId, $this->getHomeCorporationIds(), true);
    }

    /**
     * Put a corporation on the configured list, so its members are members
     * here and its own profile taxes them. Before settings are in tax
     * profiles a corporation is configured by having settings, so there is
     * nothing to add.
     */
    public function addConfiguredCorporation(int $corporationId): void
    {
        if (!$this->usesTaxProfiles() || $this->isConfiguredCorporation($corporationId)) {
            return;
        }

        $ids = array_map('intval', (array) $this->getSettingForCorporation(SettingsTransfer::CONFIGURED_KEY, null, []));
        $ids[] = $corporationId;
        $ids = array_values(array_unique($ids));
        sort($ids);

        $this->updateGlobalSetting(SettingsTransfer::CONFIGURED_KEY, $ids, 'json');
    }

    /**
     * The configured corporations, by name.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllCorporations()
    {
        $query = DB::table('corporation_infos');

        if ($this->usesTaxProfiles()) {
            $query->whereIn('corporation_id', $this->getHomeCorporationIds());
        } else {
            $query->whereIn('corporation_id', function ($query) {
                $query->select('corporation_id')
                    ->from('mining_manager_settings')
                    ->whereNotNull('corporation_id')
                    ->distinct();
            });
        }

        return $query->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();
    }

    /**
     * Test price provider connection
     *
     * @return array
     */
    public function testPriceProviderConnection(): array
    {
        $provider = $this->getSetting('price_provider', 'eve_market');

        // Test connection logic here
        // This is a placeholder - implement actual testing

        return [
            'provider' => $provider,
            'status' => 'connected',
            'latency' => '123ms',
        ];
    }

    /**
     * Cast value to appropriate type
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function castValue($value, string $type)
    {
        return match($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float', 'decimal' => (float) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }

    /**
     * Detect type of value
     *
     * @param mixed $value
     * @return string
     */
    private function detectType($value): string
    {
        return match(true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
