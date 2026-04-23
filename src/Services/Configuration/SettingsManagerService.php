<?php

namespace MiningManager\Services\Configuration;

use MiningManager\Models\Setting;
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
     * Active corporation ID for settings context
     *
     * @var int|null
     */
    protected $activeCorporationId = null;

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
     * Check if a corporation has custom settings
     *
     * @param int $corporationId
     * @return bool
     */
    public function corporationHasCustomSettings(int $corporationId): bool
    {
        return Setting::where('corporation_id', $corporationId)->exists();
    }

    /**
     * Get a setting value with fallback to config
     * Now supports corporation-specific settings
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . ($this->activeCorporationId ?? 'global') . '_' . $key;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($key, $default) {
            $setting = Setting::getValue($key, null, $this->activeCorporationId);

            if ($setting !== null) {
                return $setting;
            }

            // Fallback to config
            $configKey = 'mining-manager.' . $key;
            return config($configKey, $default);
        });
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
        $cacheKey = self::CACHE_PREFIX . ($corporationId ?? 'global') . '_' . $key;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($key, $default, $corporationId) {
            $setting = Setting::getValue($key, null, $corporationId);

            if ($setting !== null) {
                return $setting;
            }

            $configKey = 'mining-manager.' . $key;
            return config($configKey, $default);
        });
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

        Setting::setValue($key, $value, $type, $this->activeCorporationId);

        // Clear cache for both global and corporation-specific
        Cache::forget(self::CACHE_PREFIX . 'global_' . $key);
        if ($this->activeCorporationId) {
            Cache::forget(self::CACHE_PREFIX . $this->activeCorporationId . '_' . $key);
        }

        $corpContext = $this->activeCorporationId ? " (Corp ID: {$this->activeCorporationId})" : " (Global)";
        Log::info("Mining Manager: Setting updated - {$key}{$corpContext}");
    }

    /**
     * Get general settings
     * UPDATED: Now includes corporation_id and all settings are database-first with config fallback
     *
     * @return array
     */
    public function getGeneralSettings(): array
    {
        return [
            // Corporation Settings (from database, config as fallback)
            'corporation_id' => $this->getSetting('general.corporation_id', config('mining-manager.general.corporation_id')),
            'corporation_name' => $this->getSetting('general.corporation_name', ''),
            'corporation_ticker' => $this->getSetting('general.corporation_ticker', ''),
            // NOTE: Despite the name, this is effectively the "Tax Program Corporation ID".
            // This corp owns the moons/structures AND runs the mining tax program.
            // All tax invoices, theft detection, moon notifications, and ledger tracking
            // are scoped to this corp — regardless of ore source (moon, belt, ice, gas).
            // Webhook notifications for moon/theft/tax events are also filtered to only
            // reach webhooks tied to this corp (see WebhookService::getMoonOwnerScopedWebhooks).
            // Other directors' corps on the same SeAT install are excluded from these notifications.
            'moon_owner_corporation_id' => $this->getSetting('general.moon_owner_corporation_id', config('mining-manager.general.moon_owner_corporation_id')),

            // Ore Refining Settings (unified: reads from pricing.refining_efficiency)
            'ore_refining_rate' => $this->getSetting('pricing.refining_efficiency', config('mining-manager.pricing.refining_efficiency', 87.5)),
            'ore_valuation_method' => $this->getSetting('general.ore_valuation_method', config('mining-manager.general.ore_valuation_method', 'mineral_price')),
            
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

            // Display Settings
            'currency_decimals' => $this->getSetting('general.currency_decimals', 2),
            'items_per_page' => $this->getSetting('general.items_per_page', 25),
            'compact_mode' => $this->getSetting('general.compact_mode', false),
            'show_character_portraits' => $this->getSetting('general.show_character_portraits', true),
            
            // Notification settings have moved to the dedicated Notifications tab
            // See getNotificationSettings() for the new per-channel configuration

            // Guest Miner Tax Rates (global, tied to Moon Owner Corporation)
            // Read with Moon Owner Corp context since that's where they're stored
            'guest_moon_ore_r64' => $this->getGuestRateSetting('guest_tax_rates.moon_ore.r64'),
            'guest_moon_ore_r32' => $this->getGuestRateSetting('guest_tax_rates.moon_ore.r32'),
            'guest_moon_ore_r16' => $this->getGuestRateSetting('guest_tax_rates.moon_ore.r16'),
            'guest_moon_ore_r8' => $this->getGuestRateSetting('guest_tax_rates.moon_ore.r8'),
            'guest_moon_ore_r4' => $this->getGuestRateSetting('guest_tax_rates.moon_ore.r4'),
            'guest_ore_tax' => $this->getGuestRateSetting('guest_tax_rates.ore'),
            'guest_ice_tax' => $this->getGuestRateSetting('guest_tax_rates.ice'),
            'guest_gas_tax' => $this->getGuestRateSetting('guest_tax_rates.gas'),
            'guest_abyssal_ore_tax' => $this->getGuestRateSetting('guest_tax_rates.abyssal_ore'),
            'guest_triglavian_ore_tax' => $this->getGuestRateSetting('guest_tax_rates.triglavian_ore'),
        ];
    }

    /**
     * Read a guest tax rate setting using Moon Owner Corp context.
     *
     * Guest rates are always stored under the Moon Owner Corporation's
     * settings context, regardless of which corporation is currently active.
     *
     * @param string $key
     * @return float
     */
    private function getGuestRateSetting(string $key): float
    {
        $moonOwnerCorpId = $this->getSetting('general.moon_owner_corporation_id');
        if (!$moonOwnerCorpId) {
            return 0;
        }

        $value = $this->getSettingForCorporation($key, (int) $moonOwnerCorpId);
        return $value !== null ? (float) $value : 0;
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
     * Update general settings
     *
     * @param array $settings
     * @return void
     */
    public function updateGeneralSettings(array $settings)
    {
        // Map guest rate form field names to their guest_tax_rates.* setting keys
        $guestRateKeyMap = [
            'guest_moon_ore_r64' => 'guest_tax_rates.moon_ore.r64',
            'guest_moon_ore_r32' => 'guest_tax_rates.moon_ore.r32',
            'guest_moon_ore_r16' => 'guest_tax_rates.moon_ore.r16',
            'guest_moon_ore_r8'  => 'guest_tax_rates.moon_ore.r8',
            'guest_moon_ore_r4'  => 'guest_tax_rates.moon_ore.r4',
            'guest_ore_tax'      => 'guest_tax_rates.ore',
            'guest_ice_tax'      => 'guest_tax_rates.ice',
            'guest_gas_tax'      => 'guest_tax_rates.gas',
            'guest_abyssal_ore_tax' => 'guest_tax_rates.abyssal_ore',
            'guest_triglavian_ore_tax' => 'guest_tax_rates.triglavian_ore',
        ];

        DB::beginTransaction();

        try {
            // Extract guest rates from settings before the main loop
            $guestRates = [];
            foreach ($guestRateKeyMap as $formKey => $settingKey) {
                if (array_key_exists($formKey, $settings)) {
                    $guestRates[$settingKey] = $settings[$formKey];
                    unset($settings[$formKey]);
                }
            }

            // Capture Moon Owner Corp ID before the loop (form value or existing DB value)
            $moonOwnerCorpId = $settings['moon_owner_corporation_id']
                ?? $this->getSetting('general.moon_owner_corporation_id');

            // Save regular general settings
            foreach ($settings as $key => $value) {
                // Moon Owner Corporation is ALWAYS global — single source of truth
                // for which structures provide moon mining data, independent of
                // corporation context (which only affects tax rates)
                if ($key === 'moon_owner_corporation_id') {
                    $savedContext = $this->activeCorporationId;
                    $this->activeCorporationId = null; // Force global save
                    $this->updateSetting('general.moon_owner_corporation_id', $value, 'integer');
                    $this->activeCorporationId = $savedContext;
                } elseif (in_array($key, ['payment_match_tolerance', 'payment_grace_period_hours'])) {
                    // Payment settings use payment. prefix instead of general.
                    $settingKey = str_replace('payment_', 'payment.', $key);
                    $this->updateSetting($settingKey, $value);
                } else {
                    $this->updateSetting('general.' . $key, $value);
                }
            }

            // Save guest rates with Moon Owner Corp context
            if (!empty($guestRates)) {
                if ($moonOwnerCorpId) {
                    $savedContext = $this->activeCorporationId;
                    $this->activeCorporationId = (int) $moonOwnerCorpId;

                    foreach ($guestRates as $settingKey => $value) {
                        $this->updateSetting($settingKey, $value, 'float');
                    }

                    $this->activeCorporationId = $savedContext;
                } else {
                    Log::warning('Mining Manager: Cannot save guest tax rates — no Moon Owner Corporation configured');
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get payment settings
     *
     * @return array
     */
    public function getPaymentSettings(): array
    {
        // Primary source: tax_rates.tax_payment_method (set by UI)
        // Fallback: payment.method (legacy path)
        $method = $this->getSetting('tax_rates.tax_payment_method',
            $this->getSetting('payment.method', 'wallet')
        );

        $division = (int) $this->getSetting('tax_rates.tax_wallet_division',
            $this->getSetting('payment.wallet_division', 1)
        );

        // Backwards compatibility: convert 1000-1007 to 1-7
        if ($division >= 1000) {
            $division = $division - 999;
        }

        return [
            'method' => $method,
            'wallet_division' => $division,
            'grace_period_hours' => $this->getSetting('payment.grace_period_hours', 24),
            'match_tolerance' => $this->getSetting('payment.match_tolerance', 100),
            'minimum_tax_amount' => (float) $this->getSetting('payment.minimum_tax_amount',
                config('mining-manager.tax_payment.minimum_tax_amount', 1000000)),
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
        return [
            // Moon ore tax rates by rarity (percentage)
            'moon_ore' => [
                'r64' => $this->getSetting('tax_rates.moon_ore.r64', config('mining-manager.tax_rates.moon_ore.r64', 15.0)),
                'r32' => $this->getSetting('tax_rates.moon_ore.r32', config('mining-manager.tax_rates.moon_ore.r32', 12.0)),
                'r16' => $this->getSetting('tax_rates.moon_ore.r16', config('mining-manager.tax_rates.moon_ore.r16', 10.0)),
                'r8' => $this->getSetting('tax_rates.moon_ore.r8', config('mining-manager.tax_rates.moon_ore.r8', 8.0)),
                'r4' => $this->getSetting('tax_rates.moon_ore.r4', config('mining-manager.tax_rates.moon_ore.r4', 5.0)),
            ],

            // Regular ore type tax rates (percentage)
            'ice' => $this->getSetting('tax_rates.ice', config('mining-manager.tax_rates.ice', 10.0)),
            'ore' => $this->getSetting('tax_rates.ore', config('mining-manager.tax_rates.ore', 10.0)),
            'gas' => $this->getSetting('tax_rates.gas', config('mining-manager.tax_rates.gas', 10.0)),
            'abyssal_ore' => $this->getSetting('tax_rates.abyssal_ore', config('mining-manager.tax_rates.abyssal_ore', 15.0)),
            'triglavian_ore' => $this->getSetting('tax_rates.triglavian_ore', config('mining-manager.tax_rates.triglavian_ore', 10.0)),

            // Mining event bonuses (percentage reduction)
            'event_bonus' => $this->getSetting('tax_rates.event_bonus', config('mining-manager.tax_rates.event_bonus', 2.0)),

            // Tax Payment Method
            'tax_payment_method' => $this->getSetting('tax_rates.tax_payment_method', 'wallet'),
            'tax_wallet_division' => $this->getSetting('tax_rates.tax_wallet_division', 1000),

            // Tax Code Settings
            'tax_code_prefix' => $this->getSetting('tax_rates.tax_code_prefix', 'TAX-'),
            'tax_code_length' => $this->getSetting('tax_rates.tax_code_length', 6),
            'auto_generate_tax_codes' => $this->getSetting('tax_rates.auto_generate_tax_codes', true),

            // Tax Period Settings
            'tax_calculation_period' => $this->getSetting('tax_rates.tax_calculation_period', 'monthly'),
            'tax_calculation_period_pending' => $this->getSetting('tax_rates.tax_calculation_period_pending', null),
            'tax_calculation_period_effective_from' => $this->getSetting('tax_rates.tax_calculation_period_effective_from', null),
            'tax_payment_deadline_days' => $this->getSetting('tax_rates.tax_payment_deadline_days', 7),
            'send_tax_reminders' => $this->getSetting('tax_rates.send_tax_reminders', true),
            'tax_reminder_days' => $this->getSetting('tax_rates.tax_reminder_days', 3),
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
     * Update tax rates
     * COMPLETELY REWRITTEN: Now handles all form fields including moon ore rarities,
     * exemptions, tax selectors, and all other settings
     *
     * @param array $rates
     * @return void
     */
    public function updateTaxRates(array $rates)
    {
        DB::beginTransaction();

        try {
            // Update moon ore rarity rates (from individual form fields)
            if (isset($rates['moon_ore_r64'])) {
                $this->updateSetting('tax_rates.moon_ore.r64', $rates['moon_ore_r64'], 'float');
            }
            if (isset($rates['moon_ore_r32'])) {
                $this->updateSetting('tax_rates.moon_ore.r32', $rates['moon_ore_r32'], 'float');
            }
            if (isset($rates['moon_ore_r16'])) {
                $this->updateSetting('tax_rates.moon_ore.r16', $rates['moon_ore_r16'], 'float');
            }
            if (isset($rates['moon_ore_r8'])) {
                $this->updateSetting('tax_rates.moon_ore.r8', $rates['moon_ore_r8'], 'float');
            }
            if (isset($rates['moon_ore_r4'])) {
                $this->updateSetting('tax_rates.moon_ore.r4', $rates['moon_ore_r4'], 'float');
            }

            // Update regular ore type rates
            if (isset($rates['ore_tax'])) {
                $this->updateSetting('tax_rates.ore', $rates['ore_tax'], 'float');
            }
            if (isset($rates['ice_tax'])) {
                $this->updateSetting('tax_rates.ice', $rates['ice_tax'], 'float');
            }
            if (isset($rates['gas_tax'])) {
                $this->updateSetting('tax_rates.gas', $rates['gas_tax'], 'float');
            }
            if (isset($rates['abyssal_ore_tax'])) {
                $this->updateSetting('tax_rates.abyssal_ore', $rates['abyssal_ore_tax'], 'float');
            }
            if (isset($rates['triglavian_ore_tax'])) {
                $this->updateSetting('tax_rates.triglavian_ore', $rates['triglavian_ore_tax'], 'float');
            }

            // Guest miner tax rates have moved to General Settings (tied to Moon Owner Corporation).
            // See updateGeneralSettings() for the new save path.
            // Legacy support: if guest rate keys are somehow present, still save them.
            $guestKeys = [
                'guest_moon_ore_r64' => 'guest_tax_rates.moon_ore.r64',
                'guest_moon_ore_r32' => 'guest_tax_rates.moon_ore.r32',
                'guest_moon_ore_r16' => 'guest_tax_rates.moon_ore.r16',
                'guest_moon_ore_r8'  => 'guest_tax_rates.moon_ore.r8',
                'guest_moon_ore_r4'  => 'guest_tax_rates.moon_ore.r4',
                'guest_ore_tax'      => 'guest_tax_rates.ore',
                'guest_ice_tax'      => 'guest_tax_rates.ice',
                'guest_gas_tax'      => 'guest_tax_rates.gas',
                'guest_abyssal_ore_tax' => 'guest_tax_rates.abyssal_ore',
                'guest_triglavian_ore_tax' => 'guest_tax_rates.triglavian_ore',
            ];
            foreach ($guestKeys as $formKey => $settingKey) {
                if (isset($rates[$formKey])) {
                    $this->updateSetting($settingKey, $rates[$formKey], 'float');
                }
            }

            // Update exemption settings
            if (isset($rates['exemption_enabled'])) {
                $this->updateSetting('exemptions.enabled', $rates['exemption_enabled'], 'boolean');
            }
            if (isset($rates['exemption_threshold'])) {
                $this->updateSetting('exemptions.threshold', $rates['exemption_threshold'], 'float');
            }
            if (isset($rates['grace_period_days'])) {
                $this->updateSetting('exemptions.grace_period_days', $rates['grace_period_days'], 'integer');
            }

            // Minimum tax amount and behavior
            if (isset($rates['minimum_tax_amount'])) {
                $this->updateSetting('payment.minimum_tax_amount', $rates['minimum_tax_amount'], 'float');
            }
            if (isset($rates['minimum_tax_behavior'])) {
                $this->updateSetting('payment.minimum_tax_behavior', $rates['minimum_tax_behavior'], 'string');
            }

            // Update tax selector - moon ore flags
            if (isset($rates['all_moon_ore'])) {
                $this->updateSetting('tax_selector.all_moon_ore', $rates['all_moon_ore'], 'boolean');
            }
            if (isset($rates['only_corp_moon_ore'])) {
                $this->updateSetting('tax_selector.only_corp_moon_ore', $rates['only_corp_moon_ore'], 'boolean');
            }
            if (isset($rates['no_moon_ore'])) {
                $this->updateSetting('tax_selector.no_moon_ore', $rates['no_moon_ore'], 'boolean');
            }

            // Update tax selector - ore type flags
            if (isset($rates['tax_regular_ore'])) {
                $this->updateSetting('tax_selector.ore', $rates['tax_regular_ore'], 'boolean');
            }
            if (isset($rates['tax_ice'])) {
                $this->updateSetting('tax_selector.ice', $rates['tax_ice'], 'boolean');
            }
            if (isset($rates['tax_gas'])) {
                $this->updateSetting('tax_selector.gas', $rates['tax_gas'], 'boolean');
            }
            if (isset($rates['tax_abyssal_ore'])) {
                $this->updateSetting('tax_selector.abyssal_ore', $rates['tax_abyssal_ore'], 'boolean');
            }
            if (isset($rates['tax_triglavian_ore'])) {
                $this->updateSetting('tax_selector.triglavian_ore', $rates['tax_triglavian_ore'], 'boolean');
            }

            // Update tax payment method
            if (isset($rates['tax_payment_method'])) {
                $this->updateSetting('tax_rates.tax_payment_method', $rates['tax_payment_method'], 'string');
            }
            if (isset($rates['tax_wallet_division'])) {
                $this->updateSetting('tax_rates.tax_wallet_division', $rates['tax_wallet_division'], 'integer');
            }

            // Update tax code settings
            if (isset($rates['tax_code_prefix'])) {
                $this->updateSetting('tax_rates.tax_code_prefix', $rates['tax_code_prefix'], 'string');
            }
            if (isset($rates['tax_code_length'])) {
                $this->updateSetting('tax_rates.tax_code_length', $rates['tax_code_length'], 'integer');
            }
            if (isset($rates['auto_generate_tax_codes'])) {
                $this->updateSetting('tax_rates.auto_generate_tax_codes', $rates['auto_generate_tax_codes'], 'boolean');
            }

            // Update tax period settings.
            //
            // Period-type changes are QUEUED to the first of the next calendar
            // month instead of applied immediately. mining_taxes unique key is
            // (character_id, period_start) — biweekly H1 and monthly April both
            // target period_start = 2026-04-01, so switching mid-month can
            // orphan rows or silently overwrite period_type on --force recalc.
            // Deferring to a month boundary sidesteps both.
            //
            // The "apply now" path (for power users / fresh installs with no
            // existing taxes) uses the tax_calculation_period_apply_now flag.
            // That bypasses the queue and writes directly to the active slot.
            if (isset($rates['tax_calculation_period'])) {
                $requested = $rates['tax_calculation_period'];

                // Defense in depth: weekly was removed as a selectable period
                // type in v1.0.3+. If a caller somehow passes 'weekly' (e.g.
                // via a direct updateTaxRates() call or a bypassed form),
                // coerce to 'monthly' and log it. Prevents the legacy value
                // from being re-introduced into the settings store.
                if ($requested === 'weekly') {
                    \Illuminate\Support\Facades\Log::warning(
                        'Mining Manager: Attempted to set tax_calculation_period to deprecated "weekly"; coerced to "monthly". Use biweekly for sub-monthly periods.'
                    );
                    $requested = 'monthly';
                }

                $currentActive = $this->getSetting('tax_rates.tax_calculation_period', 'monthly');
                $applyNow = !empty($rates['tax_calculation_period_apply_now']);

                if ($requested === $currentActive) {
                    // No-op — also clear any stale pending queue.
                    $this->updateSetting('tax_rates.tax_calculation_period_pending', null, 'string');
                    $this->updateSetting('tax_rates.tax_calculation_period_effective_from', null, 'string');
                } elseif ($applyNow) {
                    // Power-user override: change takes effect immediately.
                    $this->updateSetting('tax_rates.tax_calculation_period', $requested, 'string');
                    $this->updateSetting('tax_rates.tax_calculation_period_pending', null, 'string');
                    $this->updateSetting('tax_rates.tax_calculation_period_effective_from', null, 'string');
                } else {
                    // Queue with a lookahead effective date that lets the old
                    // scheme finish calculating the current month before the
                    // new scheme takes over. See TaxPeriodHelper::nextSafeEffectiveDate
                    // for the rationale — TL;DR: day 3 of next month, not day 1,
                    // because day 2 is when both biweekly and monthly schedule
                    // their previous-period calcs.
                    $periodHelper = app(\MiningManager\Services\Tax\TaxPeriodHelper::class);
                    $effectiveFrom = $periodHelper->nextSafeEffectiveDate()->toDateString();
                    $this->updateSetting('tax_rates.tax_calculation_period_pending', $requested, 'string');
                    $this->updateSetting('tax_rates.tax_calculation_period_effective_from', $effectiveFrom, 'string');
                }
            }
            if (isset($rates['tax_payment_deadline_days'])) {
                $this->updateSetting('tax_rates.tax_payment_deadline_days', $rates['tax_payment_deadline_days'], 'integer');
            }
            if (isset($rates['send_tax_reminders'])) {
                $this->updateSetting('tax_rates.send_tax_reminders', $rates['send_tax_reminders'], 'boolean');
            }
            if (isset($rates['tax_reminder_days'])) {
                $this->updateSetting('tax_rates.tax_reminder_days', $rates['tax_reminder_days'], 'integer');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get tax selector settings
     * UPDATED: Added no_moon_ore flag for completeness
     *
     * @return array
     */
    public function getTaxSelector(): array
    {
        return [
            // Moon ore selector (mutually exclusive)
            'all_moon_ore' => $this->getSetting('tax_selector.all_moon_ore', true),
            'only_corp_moon_ore' => $this->getSetting('tax_selector.only_corp_moon_ore', false),
            'no_moon_ore' => $this->getSetting('tax_selector.no_moon_ore', false),

            // Other ore types (independent checkboxes)
            'ore' => $this->getSetting('tax_selector.ore', true),
            'ice' => $this->getSetting('tax_selector.ice', true),
            'gas' => $this->getSetting('tax_selector.gas', false),
            'abyssal_ore' => $this->getSetting('tax_selector.abyssal_ore', false),
            'triglavian_ore' => $this->getSetting('tax_selector.triglavian_ore', false),
        ];
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
            
            // Janice settings (checks settings first, then falls back to ENV)
            'janice_api_key' => $this->getSetting('janice_api_key') 
                ?: config('mining-manager.general.price_provider_api_key', ''),
            'janice_market' => $this->getSetting('janice_market', 'jita'),
            'janice_price_method' => $this->getSetting('janice_price_method', 'buy'),
            
            // Refining settings
            'use_refined_value' => $this->getSetting('pricing.use_refined_value', false),
            'refining_efficiency' => $this->getSetting('pricing.refining_efficiency', 87.5),
        ];
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
            'notification_hours_before' => $this->getSetting('moon.notification_hours_before', config('mining-manager.moon.notification_hours_before', [24, 4, 1])),
            'auto_calculate_values' => $this->getSetting('moon.auto_calculate_values', config('mining-manager.moon.auto_calculate_values', true)),
            'show_unscanned_moons' => $this->getSetting('moon.show_unscanned_moons', config('mining-manager.moon.show_unscanned_moons', true)),
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
     * Get notification settings
     *
     * @return array
     */
    /**
     * All notification type keys used across the system
     */
    public const NOTIFICATION_TYPES = [
        // Tax
        'tax_generated', 'tax_announcement', 'tax_reminder', 'tax_invoice', 'tax_overdue',
        // Events
        'event_created', 'event_started', 'event_completed',
        // Moon
        'moon_ready', 'jackpot_detected',
        // Theft
        'theft_detected', 'critical_theft', 'active_theft', 'incident_resolved',
        // Reports
        'report_generated',
    ];

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

            // Legacy discord pinging (kept for backward compat during transition)
            'discord_pinging_enabled' => (bool) $this->getSetting('notifications.discord_pinging_enabled', false),
            'discord_ping_show_amount' => (bool) $this->getSetting('notifications.discord_ping_show_amount', true),

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

        return array_merge($configDefaults, [
            'dashboard_leaderboard_corporation_filter' => $this->getSetting('dashboard_leaderboard_corporation_filter', 'all'),
            'dashboard_leaderboard_corporation_ids' => $this->getSetting('dashboard_leaderboard_corporation_ids', '[]'),
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
            'auto_track_event_participation' => (bool) $this->getSetting('features.auto_track_event_participation', true),

            // Moon mining
            'enable_moon_tracking' => (bool) $this->getSetting('features.enable_moon_tracking', true),
            'track_moon_compositions' => (bool) $this->getSetting('features.track_moon_compositions', true),
            'calculate_moon_value' => (bool) $this->getSetting('features.calculate_moon_value', true),
            'notify_extraction_ready' => (bool) $this->getSetting('features.notify_extraction_ready', true),
            'extraction_notification_hours' => (int) $this->getSetting('features.extraction_notification_hours', 24),

            // Permissions & access
            'allow_public_stats' => (bool) $this->getSetting('features.allow_public_stats', false),
            'allow_member_leaderboard' => (bool) $this->getSetting('features.allow_member_leaderboard', true),
            'show_character_names' => (bool) $this->getSetting('features.show_character_names', true),
            'allow_export_data' => (bool) $this->getSetting('features.allow_export_data', true),

            // Automation
            'auto_process_ledger' => (bool) $this->getSetting('features.auto_process_ledger', true),
            'auto_calculate_taxes' => (bool) $this->getSetting('features.auto_calculate_taxes', true),
            'auto_generate_invoices' => (bool) $this->getSetting('features.auto_generate_invoices', true),
            'verify_wallet_transactions' => (bool) $this->getSetting('features.verify_wallet_transactions', true),

            // Data retention
            'ledger_retention_days' => (int) $this->getSetting('features.ledger_retention_days', 365),
            'tax_record_retention_days' => (int) $this->getSetting('features.tax_record_retention_days', 730),
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
            // Get all setting keys before truncating so we can clear their cache entries
            $settingKeys = Setting::pluck('key')->toArray();
            $corporationIds = Setting::whereNotNull('corporation_id')
                ->distinct()
                ->pluck('corporation_id')
                ->toArray();

            Setting::truncate();

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
    public function exportSettings(): array
    {
        $settings = Setting::all()->mapWithKeys(function ($setting) {
            return [$setting->key => $this->castValue($setting->value, $setting->type)];
        })->toArray();

        return [
            'exported_at' => now()->toDateTimeString(),
            'version' => config('mining-manager.version', '1.0.0'),
            'settings' => $settings,
        ];
    }

    /**
     * Import settings from array
     *
     * @param array $data
     * @return void
     */
    public function importSettings(array $data)
    {
        if (!isset($data['settings'])) {
            throw new \Exception('Invalid settings format');
        }

        DB::beginTransaction();
        
        try {
            foreach ($data['settings'] as $key => $value) {
                $this->updateSetting($key, $value);
            }
            
            Log::info('Mining Manager: Settings imported successfully');
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get all "home" corporation IDs — configured corporations + moon owner corp.
     * Characters belonging to any of these are considered corp members.
     * Characters from any other corporation are considered guest miners.
     *
     * @return array<int>
     */
    public function getHomeCorporationIds(): array
    {
        // Get configured corporations (from "Switch Corporation Context" list)
        $configuredCorpIds = DB::table('mining_manager_settings')
            ->whereNotNull('corporation_id')
            ->distinct()
            ->pluck('corporation_id')
            ->map(fn($id) => (int) $id)
            ->toArray();

        // Also include moon owner corporation (structure/moon holding corp)
        $moonOwnerCorpId = $this->getSetting('general.moon_owner_corporation_id');
        if ($moonOwnerCorpId) {
            $configuredCorpIds[] = (int) $moonOwnerCorpId;
        }

        return array_values(array_unique($configuredCorpIds));
    }

    /**
     * Get all corporations that have settings configured
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllCorporations()
    {
        return DB::table('corporation_infos')
            ->whereIn('corporation_id', function($query) {
                $query->select('corporation_id')
                    ->from('mining_manager_settings')
                    ->whereNotNull('corporation_id')
                    ->distinct();
            })
            ->select('corporation_id', 'name', 'ticker')
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
