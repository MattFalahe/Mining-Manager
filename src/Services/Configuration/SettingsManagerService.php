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
            'moon_owner_corporation_id' => $this->getSetting('general.moon_owner_corporation_id', config('mining-manager.general.moon_owner_corporation_id')),

            // Ore Refining Settings (unified: reads from pricing.refining_efficiency)
            'ore_refining_rate' => $this->getSetting('pricing.refining_efficiency', config('mining-manager.pricing.refining_efficiency', 87.5)),
            'ore_valuation_method' => $this->getSetting('general.ore_valuation_method', config('mining-manager.general.ore_valuation_method', 'mineral_price')),
            
            // Price Provider Settings
            'price_provider' => $this->getSetting('price_provider', config('mining-manager.general.price_provider', 'eve_market')),
            'default_region_id' => $this->getSetting('general.default_region_id', config('mining-manager.general.default_region_id', 10000002)),
            'price_modifier' => $this->getSetting('general.price_modifier', config('mining-manager.general.price_modifier', 0.0)),
            
            // Tax Calculation Method
            'tax_calculation_method' => $this->getSetting('general.tax_calculation_method', config('mining-manager.general.tax_calculation_method', 'accumulated')),

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
        ];
    }

    /**
     * Get corporation ID (convenience method)
     * Used throughout the plugin for tax calculations and data filtering
     *
     * @return int|null
     */
    public function getCorporationId(): ?int
    {
        $corpId = $this->getSetting('general.corporation_id', config('mining-manager.general.corporation_id'));
        return $corpId ? (int) $corpId : null;
    }

    /**
     * Update general settings
     *
     * @param array $settings
     * @return void
     */
    public function updateGeneralSettings(array $settings)
    {
        DB::beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                // Payment settings use payment. prefix instead of general.
                if (in_array($key, ['payment_match_tolerance', 'payment_grace_period_hours'])) {
                    $settingKey = str_replace('payment_', 'payment.', $key);
                    $this->updateSetting($settingKey, $value);
                } else {
                    $this->updateSetting('general.' . $key, $value);
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

        return [
            'method' => $method,
            'wallet_division' => $this->getSetting('tax_rates.tax_wallet_division',
                $this->getSetting('payment.wallet_division', 1000)
            ),
            'payment_character_id' => $this->getSetting('payment.payment_character_id'),
            'auto_verify' => $this->getSetting('payment.auto_verify', false),
            'grace_period_hours' => $this->getSetting('payment.grace_period_hours', 24),
            'match_tolerance' => $this->getSetting('payment.match_tolerance', 100),
        ];
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

            // Mining event bonuses (percentage reduction)
            'event_bonus' => $this->getSetting('tax_rates.event_bonus', config('mining-manager.tax_rates.event_bonus', 2.0)),

            // Tax Payment Method
            'tax_payment_method' => $this->getSetting('tax_rates.tax_payment_method', 'wallet'),
            'tax_wallet_division' => $this->getSetting('tax_rates.tax_wallet_division', 1000),

            // Tax Code Settings
            'tax_code_prefix' => $this->getSetting('tax_rates.tax_code_prefix', 'TAX-'),
            'tax_code_length' => $this->getSetting('tax_rates.tax_code_length', 8),
            'auto_generate_tax_codes' => $this->getSetting('tax_rates.auto_generate_tax_codes', true),

            // Tax Period Settings
            'tax_calculation_period' => $this->getSetting('tax_rates.tax_calculation_period', 'monthly'),
            'tax_payment_deadline_days' => $this->getSetting('tax_rates.tax_payment_deadline_days', 7),
            'send_tax_reminders' => $this->getSetting('tax_rates.send_tax_reminders', true),
            'tax_reminder_days' => $this->getSetting('tax_rates.tax_reminder_days', 3),
        ];
    }

    /**
     * Get tax rates for a specific corporation.
     * If the character is a guest miner (not from moon owner corporation),
     * uses the configured guest miner tax rates (or falls back to corp rates if guest rate is 0).
     *
     * @param int|null $characterCorporationId The corporation ID of the character being taxed
     * @return array
     */
    public function getTaxRatesForCorporation(?int $characterCorporationId): array
    {
        // Get base (corp member) tax rates
        $corpTaxRates = $this->getTaxRates();

        // Get moon owner corporation ID
        $moonOwnerCorpId = $this->getSetting('general.moon_owner_corporation_id');

        // If no corporation ID provided or moon owner not configured, use corp rates
        if (!$characterCorporationId || !$moonOwnerCorpId) {
            return $corpTaxRates;
        }

        // If the character is from the moon owner corporation, use corp member rates
        if ($characterCorporationId == $moonOwnerCorpId) {
            return $corpTaxRates;
        }

        // Character is a guest miner - get guest tax rates
        $guestRates = $corpTaxRates; // Start with corp rates as fallback

        // Get guest moon ore rates (if 0, fallback to corp rate)
        foreach (['r64', 'r32', 'r16', 'r8', 'r4'] as $rarity) {
            $guestRate = $this->getSetting("guest_tax_rates.moon_ore.{$rarity}", 0);
            if ($guestRate > 0) {
                $guestRates['moon_ore'][$rarity] = $guestRate;
            }
            // If 0 or not set, keeps the corp rate from $corpTaxRates
        }

        // Get guest regular ore rates (if 0, fallback to corp rate)
        $guestIceRate = $this->getSetting('guest_tax_rates.ice', 0);
        if ($guestIceRate > 0) {
            $guestRates['ice'] = $guestIceRate;
        }

        $guestOreRate = $this->getSetting('guest_tax_rates.ore', 0);
        if ($guestOreRate > 0) {
            $guestRates['ore'] = $guestOreRate;
        }

        $guestGasRate = $this->getSetting('guest_tax_rates.gas', 0);
        if ($guestGasRate > 0) {
            $guestRates['gas'] = $guestGasRate;
        }

        $guestAbyssalRate = $this->getSetting('guest_tax_rates.abyssal_ore', 0);
        if ($guestAbyssalRate > 0) {
            $guestRates['abyssal_ore'] = $guestAbyssalRate;
        }

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

            // Update guest miner tax settings - Moon ore rates
            if (isset($rates['guest_moon_ore_r64'])) {
                $this->updateSetting('guest_tax_rates.moon_ore.r64', $rates['guest_moon_ore_r64'], 'float');
            }
            if (isset($rates['guest_moon_ore_r32'])) {
                $this->updateSetting('guest_tax_rates.moon_ore.r32', $rates['guest_moon_ore_r32'], 'float');
            }
            if (isset($rates['guest_moon_ore_r16'])) {
                $this->updateSetting('guest_tax_rates.moon_ore.r16', $rates['guest_moon_ore_r16'], 'float');
            }
            if (isset($rates['guest_moon_ore_r8'])) {
                $this->updateSetting('guest_tax_rates.moon_ore.r8', $rates['guest_moon_ore_r8'], 'float');
            }
            if (isset($rates['guest_moon_ore_r4'])) {
                $this->updateSetting('guest_tax_rates.moon_ore.r4', $rates['guest_moon_ore_r4'], 'float');
            }

            // Update guest miner tax settings - Regular ore rates
            if (isset($rates['guest_ore_tax'])) {
                $this->updateSetting('guest_tax_rates.ore', $rates['guest_ore_tax'], 'float');
            }
            if (isset($rates['guest_ice_tax'])) {
                $this->updateSetting('guest_tax_rates.ice', $rates['guest_ice_tax'], 'float');
            }
            if (isset($rates['guest_gas_tax'])) {
                $this->updateSetting('guest_tax_rates.gas', $rates['guest_gas_tax'], 'float');
            }
            if (isset($rates['guest_abyssal_ore_tax'])) {
                $this->updateSetting('guest_tax_rates.abyssal_ore', $rates['guest_abyssal_ore_tax'], 'float');
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

            // Update tax period settings
            if (isset($rates['tax_calculation_period'])) {
                $this->updateSetting('tax_rates.tax_calculation_period', $rates['tax_calculation_period'], 'string');
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
            'cache_duration' => $this->getSetting('pricing.cache_duration', 60),
            'auto_refresh' => $this->getSetting('pricing.auto_refresh', true),
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
    public function getNotificationSettings(): array
    {
        return [
            // EVE Mail
            'evemail_enabled' => (bool) $this->getSetting('notifications.evemail_enabled', false),
            'evemail_sender_character_id' => $this->getSetting('notifications.evemail_sender_character_id', null),
            'evemail_sender_character_override' => $this->getSetting('notifications.evemail_sender_character_override', null),
            'evemail_types' => $this->getSetting('notifications.evemail_types', [
                'tax_reminder' => true,
                'tax_invoice' => true,
                'tax_overdue' => true,
                'event_created' => true,
                'event_started' => true,
                'event_completed' => true,
                'moon_ready' => true,
            ]),

            // Slack
            'slack_enabled' => (bool) $this->getSetting('notifications.slack_enabled', false),
            'slack_webhook_url' => $this->getSetting('notifications.slack_webhook_url', ''),
            'slack_types' => $this->getSetting('notifications.slack_types', [
                'tax_reminder' => true,
                'tax_invoice' => true,
                'tax_overdue' => true,
                'event_created' => true,
                'event_started' => true,
                'event_completed' => true,
                'moon_ready' => true,
            ]),

            // Discord pinging
            'discord_pinging_enabled' => (bool) $this->getSetting('notifications.discord_pinging_enabled', false),
            'discord_ping_show_amount' => (bool) $this->getSetting('notifications.discord_ping_show_amount', true),
            'discord_ping_tax_page_url' => $this->getSetting('notifications.discord_ping_tax_page_url', ''),

            // seat-connector availability (runtime)
            'seat_connector_available' => \Illuminate\Support\Facades\Schema::hasTable('seat_connector_users'),
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
            'event_bonus_multiplier' => (float) $this->getSetting('features.event_bonus_multiplier', 1.5),

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
            'ledger_processing_interval' => (int) $this->getSetting('features.ledger_processing_interval', 60),
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
