<?php

namespace MattFalahe\Seat\MiningManager\Http\Controllers;

use Seat\Web\Http\Controllers\Controller;
use MattFalahe\Seat\MiningManager\Models\Setting;
use MattFalahe\Seat\MiningManager\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    protected $settingsService;
    
    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }
    
    public function index()
    {
        $settings = $this->settingsService->getAllSettings();
        $corporations = $this->getCorporationList();
        $priceProviders = $this->getPriceProviders();
        
        return view('mining-manager::settings.index', compact('settings', 'corporations', 'priceProviders'));
    }
    
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'corporation_id' => 'required|exists:corporation_infos,corporation_id',
            'refining_efficiency' => 'required|numeric|min:0|max:100',
            'price_modifier' => 'required|numeric|min:0|max:200',
            'minimum_contract_value' => 'required|numeric|min:0',
            'contract_expiry_days' => 'required|integer|min:1|max:30',
            'contract_issuer_id' => 'required|exists:character_infos,character_id',
        ]);
        
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        
        // Save general settings
        $this->settingsService->updateSettings([
            'corporation_id' => $request->corporation_id,
            'refining_efficiency' => $request->refining_efficiency,
            'price_source' => $request->price_source,
            'price_modifier' => $request->price_modifier,
            'price_provider_key' => $request->price_provider_key,
            'tax_calculation_method' => $request->tax_calculation_method,
            'minimum_contract_value' => $request->minimum_contract_value,
            'contract_expiry_days' => $request->contract_expiry_days,
            'contract_tag' => $request->contract_tag,
            'contract_issuer_id' => $request->contract_issuer_id,
            'enable_notifications' => $request->has('enable_notifications'),
            'auto_generate_invoices' => $request->has('auto_generate_invoices'),
            'enable_events' => $request->has('enable_events'),
            'enable_moon_tracking' => $request->has('enable_moon_tracking'),
        ]);
        
        // Save tax rates
        $taxRates = [];
        foreach ($request->tax_rates as $category => $rate) {
            $taxRates[$category] = [
                'rate' => $rate,
                'enabled' => $request->has("tax_enabled.$category"),
                'corp_moons_only' => $request->has("corp_moons_only.$category"),
            ];
        }
        $this->settingsService->updateTaxRates($taxRates);
        
        // Clear cache
        Cache::tags(['mining_manager'])->flush();
        
        return redirect()->route('mining.settings')
            ->with('success', 'Settings updated successfully!');
    }
    
    private function getCorporationList()
    {
        return \DB::table('corporation_infos')
            ->select('corporation_id', 'name')
            ->orderBy('name')
            ->get();
    }
    
    private function getPriceProviders()
    {
        return [
            'market' => 'EVE Market Prices',
            'janice' => 'EVE Janice',
            'fuzzwork' => 'Fuzzwork Market Data',
            'custom' => 'Custom Prices',
        ];
    }
}
