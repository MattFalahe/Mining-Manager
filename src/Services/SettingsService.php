<?php

namespace MattFalahe\Seat\MiningManager\Services;

use MattFalahe\Seat\MiningManager\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    protected $cacheKey = 'mining_manager_settings';
    protected $cacheTTL = 3600; // 1 hour
    
    public function getAllSettings()
    {
        return Cache::remember($this->cacheKey, $this->cacheTTL, function () {
            $settings = new \stdClass();
            
            // General settings
            $settings->corporation_id = Setting::get('corporation_id');
            $settings->refining_efficiency = Setting::get('refining_efficiency', 90.6);
            $settings->price_source = Setting::get('price_source', 'market');
            $settings->price_modifier = Setting::get('price_modifier', 100);
            $settings->price_provider_key = Setting::get('price_provider_key');
            $settings->tax_calculation_method = Setting::get('tax_calculation_method', 'main_character');
            $settings->valuation_method = Setting::get('valuation_method', 'refined');
            
            // Contract settings
            $settings->minimum_contract_value = Setting::get('minimum_contract_value', 1000000);
            $settings->contract_expiry_days = Setting::get('contract_expiry_days', 7);
            $settings->contract_tag = Setting::get('contract_tag', 'Mining Tax');
            $settings->contract_issuer_id = Setting::get('contract_issuer_id');
            $settings->auto_generate_invoices = Setting::get('auto_generate_invoices', false);
            
            // Tax rates
            $settings->taxes = Setting::get('taxes', $this->getDefaultTaxRates());
            
            // Features
            $settings->enable_notifications = Setting::get('enable_notifications', true);
            $settings->enable_events = Setting::get('enable_events', true);
            $settings->enable_moon_tracking = Setting::get('enable_moon_tracking', true);
            $settings->enable_analytics = Setting::get('enable_analytics', true);
            $settings->enable_api = Setting::get('enable_api', false);
            $settings->enable_reports = Setting::get('enable_reports', true);
            
            // Additional data
            $settings->availableIssuers = $this->getAvailableIssuers();
            $settings->updated_at = Setting::orderBy('updated_at', 'desc')->first()->updated_at ?? null;
            
            return $settings;
        });
    }
    
    public function updateSettings(array $data)
    {
        foreach ($data as $key => $value) {
            $type = 'string';
            
            if (is_bool($value)) {
                $type = 'boolean';
            } elseif (is_numeric($value)) {
                $type = strpos($value, '.') !== false ? 'float' : 'integer';
            } elseif (is_array($value)) {
                $type = 'array';
            }
            
            Setting::set($key, $value, $type);
        }
        
        $this->clearCache();
    }
    
    public function updateTaxRates(array $taxRates)
    {
        Setting::set('taxes', $taxRates, 'array');
        $this->clearCache();
    }
    
    public function resetToDefaults()
    {
        Setting::truncate();
        
        // Set default values
        $defaults = config('mining-manager.defaults');
        foreach ($defaults as $key => $value) {
            Setting::set($key, $value);
        }
        
        // Set default tax rates
        Setting::set('taxes', $this->getDefaultTaxRates(), 'array');
        
        $this->clearCache();
    }
    
    protected function getDefaultTaxRates()
    {
        return [
            'standard' => ['rate' => 10, 'enabled' => true, 'corp_moons_only' => false],
            'ice' => ['rate' => 10, 'enabled' => true, 'corp_moons_only' => false],
            'gas' => ['rate' => 10, 'enabled' => true, 'corp_moons_only' => false],
            'moon_r4' => ['rate' => 10, 'enabled' => true, 'corp_moons_only' => false],
            'moon_r8' => ['rate' => 12, 'enabled' => true, 'corp_moons_only' => false],
            'moon_r16' => ['rate' => 15, 'enabled' => true, 'corp_moons_only' => false],
            'moon_r32' => ['rate' => 18, 'enabled' => true, 'corp_moons_only' => true],
            'moon_r64' => ['rate' => 20, 'enabled' => true, 'corp_moons_only' => true],
            'abyssal' => ['rate' => 5, 'enabled' => false, 'corp_moons_only' => false],
        ];
    }
    
    protected function getAvailableIssuers()
    {
        // Get characters that can issue contracts (corp directors, etc.)
        return \DB::table('character_infos')
            ->join('refresh_tokens', 'character_infos.character_id', '=', 'refresh_tokens.character_id')
            ->select('character_infos.character_id', 'character_infos.name')
            ->where('character_infos.corporation_id', Setting::get('corporation_id'))
            ->orderBy('character_infos.name')
            ->get();
    }
    
    protected function clearCache()
    {
        Cache::forget($this->cacheKey);
        Cache::tags(['mining_manager'])->flush();
    }
}
