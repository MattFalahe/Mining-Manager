<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    /**
     * Settings manager service
     *
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * Constructor
     *
     * @param SettingsManagerService $settingsService
     */
    public function __construct(SettingsManagerService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Display settings page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Get all current settings
        $settings = [
            'general' => $this->settingsService->getGeneralSettings(),
            'contract' => $this->settingsService->getContractSettings(),
            'tax_rates' => $this->settingsService->getTaxRates(),
            'tax_selector' => $this->settingsService->getTaxSelector(),
            'exemptions' => $this->settingsService->getExemptions(),
            'pricing' => $this->settingsService->getPricingSettings(),
            'events' => $this->settingsService->getEventSettings(),
            'moon' => $this->settingsService->getMoonSettings(),
            'reports' => $this->settingsService->getReportSettings(),
            'notifications' => $this->settingsService->getNotificationSettings(),
            'dashboard' => $this->settingsService->getDashboardSettings(),
            'features' => $this->settingsService->getFeatureFlags(),
        ];

        return view('mining-manager::settings.index', compact('settings'));
    }

    /**
     * Update general settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateGeneral(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'corporation_id' => 'nullable|integer',
            'ore_refining_rate' => 'required|numeric|min:0|max:100',
            'ore_valuation_method' => 'required|in:ore_price,mineral_price',
            'price_provider' => 'required|in:eve_market,janice',
            'price_provider_api_key' => 'nullable|string',
            'price_modifier' => 'nullable|numeric|min:-100|max:100',
            'tax_calculation_method' => 'required|in:accumulated,individually',
            'default_region_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $this->settingsService->updateGeneralSettings($validator->validated());

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'General settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating settings: ' . $e->getMessage());
        }
    }

    /**
     * Update contract settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateContract(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'issuer_character_name' => 'nullable|string|max:255',
            'contract_tag' => 'nullable|string|max:255',
            'minimum_tax_value' => 'required|numeric|min:0',
            'expire_in_days' => 'required|integer|min:1|max:30',
            'auto_generate' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $this->settingsService->updateContractSettings($validator->validated());

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Contract settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating contract settings: ' . $e->getMessage());
        }
    }

    /**
     * Update tax rates
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateTaxRates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Moon ore tax rates
            'moon_ore.r64' => 'required|numeric|min:0|max:100',
            'moon_ore.r32' => 'required|numeric|min:0|max:100',
            'moon_ore.r16' => 'required|numeric|min:0|max:100',
            'moon_ore.r8' => 'required|numeric|min:0|max:100',
            'moon_ore.r4' => 'required|numeric|min:0|max:100',
            // Other ore types
            'ice' => 'required|numeric|min:0|max:100',
            'ore' => 'required|numeric|min:0|max:100',
            'gas' => 'required|numeric|min:0|max:100',
            'abyssal_ore' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $this->settingsService->updateTaxRates($validator->validated());

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Tax rates updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating tax rates: ' . $e->getMessage());
        }
    }

    /**
     * Update tax selector
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateTaxSelector(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'all_moon_ore' => 'boolean',
            'only_corp_moon_ore' => 'boolean',
            'ore' => 'boolean',
            'ice' => 'boolean',
            'gas' => 'boolean',
            'abyssal_ore' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $validator->validated();
            
            // Convert to boolean (checkboxes not sent if unchecked)
            $taxSelector = [
                'all_moon_ore' => $request->has('all_moon_ore'),
                'only_corp_moon_ore' => $request->has('only_corp_moon_ore'),
                'ore' => $request->has('ore'),
                'ice' => $request->has('ice'),
                'gas' => $request->has('gas'),
                'abyssal_ore' => $request->has('abyssal_ore'),
            ];

            $this->settingsService->updateTaxSelector($taxSelector);

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Tax selector updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating tax selector: ' . $e->getMessage());
        }
    }

    /**
     * Update exemptions
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateExemptions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'threshold' => 'required|numeric|min:0',
            'grace_period_days' => 'required|integer|min:0|max:30',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $this->settingsService->updateExemptions($validator->validated());

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Exemption settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating exemptions: ' . $e->getMessage());
        }
    }

    /**
     * Update pricing settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePricing(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'price_type' => 'required|in:sell,buy,average',
            'cache_duration' => 'required|integer|min:1|max:1440',
            'auto_refresh' => 'boolean',
            'fallback_to_jita' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $this->settingsService->updatePricingSettings($validator->validated());

            // Clear price cache when settings change
            cache()->tags(['mining-manager', 'prices'])->flush();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Pricing settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating pricing settings: ' . $e->getMessage());
        }
    }

    /**
     * Update feature flags
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateFeatures(Request $request)
    {
        try {
            $features = [
                'mining_ledger' => $request->has('mining_ledger'),
                'tax_calculation' => $request->has('tax_calculation'),
                'tax_invoices' => $request->has('tax_invoices'),
                'mining_events' => $request->has('mining_events'),
                'moon_extractions' => $request->has('moon_extractions'),
                'reports' => $request->has('reports'),
                'analytics' => $request->has('analytics'),
                'wallet_verification' => $request->has('wallet_verification'),
                'notifications' => $request->has('notifications'),
                'price_caching' => $request->has('price_caching'),
            ];

            $this->settingsService->updateFeatureFlags($features);

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Feature settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error updating feature settings: ' . $e->getMessage());
        }
    }

    /**
     * Reset all settings to defaults
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function reset()
    {
        try {
            $this->settingsService->resetToDefaults();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'All settings reset to defaults successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error resetting settings: ' . $e->getMessage());
        }
    }

    /**
     * Export settings as JSON
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function export()
    {
        try {
            $settings = $this->settingsService->exportSettings();

            return response()->json($settings, 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="mining-manager-settings-' . date('Y-m-d') . '.json"',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error exporting settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import settings from JSON
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function import(Request $request)
    {
        $request->validate([
            'settings_file' => 'required|file|mimes:json,txt',
        ]);

        try {
            $file = $request->file('settings_file');
            $contents = file_get_contents($file->path());
            $settings = json_decode($contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON file: ' . json_last_error_msg());
            }

            $this->settingsService->importSettings($settings);

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Settings imported successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error importing settings: ' . $e->getMessage());
        }
    }

    /**
     * Clear all caches
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function clearCache()
    {
        try {
            cache()->tags(['mining-manager'])->flush();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'All caches cleared successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error clearing cache: ' . $e->getMessage());
        }
    }

    /**
     * Test price provider connection
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPriceProvider()
    {
        try {
            $result = $this->settingsService->testPriceProviderConnection();

            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 400);
        }
    }
}
