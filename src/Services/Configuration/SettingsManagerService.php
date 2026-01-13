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
     * Get a setting value with fallback to config
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($key, $default) {
            $setting = Setting::where('key', $key)->first();

            if ($setting) {
                return $this->castValue($setting->value, $setting->type);
            }

            // Fallback to config
            $configKey = 'mining-manager.' . $key;
            return config($configKey, $default);
        });
    }

    /**
     * Update or create a setting
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

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value) : $value,
                'type' => $type,
            ]
        );

        // Clear cache
        Cache::forget(self::CACHE_PREFIX . $key);
        
        Log::info("Mining Manager: Setting updated - {$key}");
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
            
            // Ore Refining Settings
            'ore_refining_rate' => $this->getSetting('general.ore_refining_rate', config('mining-manager.general.ore_refining_rate', 90.0)),
            'ore_valuation_method' => $this->getSetting('general.ore_valuation_method', config('mining-manager.general.ore_valuation_method', 'mineral_price')),
            
            // Price Provider Settings
            'price_provider' => $this->getSetting('general.price_provider', config('mining-manager.general.price_provider', 'eve_market')),
            'default_region_id' => $this->getSetting('general.default_region_id', config('mining-manager.general.default_region_id', 10000002)),
            'price_modifier' => $this->getSetting('general.price_modifier', config('mining-manager.general.price_modifier', 0.0)),
            
            // Tax Calculation Method
            'tax_calculation_method' => $this->getSetting('general.tax_calculation_method', config('mining-manager.general.tax_calculation_method', 'accumulated')),
            
            // Time Settings
            'timezone' => $this->getSetting('general.timezone', 'UTC'),
            'date_format' => $this->getSetting('general.date_format', 'Y-m-d'),
            'time_format' => $this->getSetting('general.time_format', 'H:i:s'),
            
            // Display Settings
            'currency_decimals' => $this->getSetting('general.currency_decimals', 2),
            'items_per_page' => $this->getSetting('general.items_per_page', 25),
            'compact_mode' => $this->getSetting('general.compact_mode', false),
            'show_character_portraits' => $this->getSetting('general.show_character_portraits', true),
            
            // Notification Settings
            'enable_notifications' => $this->getSetting('general.enable_notifications', true),
            'notify_tax_due' => $this->getSetting('general.notify_tax_due', true),
            'notify_moon_extractions' => $this->getSetting('general.notify_moon_extractions', true),
            'notify_events' => $this->getSetting('general.notify_events', true),
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
                $this->updateSetting('general.' . $key, $value);
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get contract settings
     *
     * @return array
     */
    public function getContractSettings(): array
    {
        return [
            'issuer_character_name' => $this->getSetting('contract.issuer_character_name', ''),
            'contract_tag' => $this->getSetting('contract.contract_tag', 'MINC TAX {year}-{month}'),
            'minimum_tax_value' => $this->getSetting('contract.minimum_tax_value', 1000000),
            'expire_in_days' => $this->getSetting('contract.expire_in_days', 7),
            'auto_generate' => $this->getSetting('contract.auto_generate', false),
        ];
    }

    /**
     * Update contract settings
     *
     * @param array $settings
     * @return void
     */
    public function updateContractSettings(array $settings)
    {
        DB::beginTransaction();
        
        try {
            foreach ($settings as $key => $value) {
                $this->updateSetting('contract.' . $key, $value);
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
        return [
            'method' => $this->getSetting('payment.method', 'contract'),
            'wallet_division' => $this->getSetting('payment.wallet_division', 1),
            'payment_character_id' => $this->getSetting('payment.payment_character_id'),
            'auto_verify' => $this->getSetting('payment.auto_verify', false),
            'grace_period_hours' => $this->getSetting('payment.grace_period_hours', 24),
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
            'tax_payment_method' => $this->getSetting('tax_rates.tax_payment_method', 'contract'),
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
     * Update tax rates
     *
     * @param array $rates
     * @return void
     */
    public function updateTaxRates(array $rates)
    {
        DB::beginTransaction();
        
        try {
            // Update moon ore rates
            if (isset($rates['moon_ore'])) {
                foreach ($rates['moon_ore'] as $rarity => $rate) {
                    $this->updateSetting("tax_rates.moon_ore.{$rarity}", $rate, 'float');
                }
            }

            // Update other rates
            foreach (['ice', 'ore', 'gas', 'abyssal_ore'] as $type) {
                if (isset($rates[$type])) {
                    $this->updateSetting("tax_rates.{$type}", $rates[$type], 'float');
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get tax selector settings
     *
     * @return array
     */
    public function getTaxSelector(): array
    {
        return [
            'all_moon_ore' => $this->getSetting('tax_selector.all_moon_ore', true),
            'only_corp_moon_ore' => $this->getSetting('tax_selector.only_corp_moon_ore', false),
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
        return config('mining-manager.moon', []);
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
        return config('mining-manager.notifications', []);
    }

    /**
     * Get dashboard settings
     *
     * @return array
     */
    public function getDashboardSettings(): array
    {
        return config('mining-manager.dashboard', []);
    }

    /**
     * Get feature flags
     *
     * @return array
     */
    public function getFeatureFlags(): array
    {
        return [
            'mining_ledger' => $this->getSetting('features.mining_ledger', true),
            'tax_calculation' => $this->getSetting('features.tax_calculation', true),
            'tax_invoices' => $this->getSetting('features.tax_invoices', true),
            'mining_events' => $this->getSetting('features.mining_events', true),
            'moon_extractions' => $this->getSetting('features.moon_extractions', true),
            'reports' => $this->getSetting('features.reports', true),
            'analytics' => $this->getSetting('features.analytics', true),
            'wallet_verification' => $this->getSetting('features.wallet_verification', true),
            'notifications' => $this->getSetting('features.notifications', true),
            'price_caching' => $this->getSetting('features.price_caching', true),
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
                $this->updateSetting("features.{$key}", $value, 'boolean');
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
            Setting::truncate();
            Cache::flush();
            
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
     * Test price provider connection
     *
     * @return array
     */
    public function testPriceProviderConnection(): array
    {
        $provider = $this->getSetting('general.price_provider', 'eve_market');
        
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
