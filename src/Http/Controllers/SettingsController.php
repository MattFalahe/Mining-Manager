<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Configuration\SettingsManagerService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

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
     * Clear all mining manager settings caches after any settings update.
     * Ensures changes take effect immediately instead of waiting for cache TTL.
     */
    private function clearSettingsCache(): void
    {
        try {
            cache()->tags(['mining-manager'])->flush();
        } catch (\Exception $e) {
            // File/database cache driver doesn't support tags — clear individually
            Log::debug('Mining Manager: Cache driver does not support tags, clearing settings cache prefix');
        }

        // Always clear the settings-level cache keys regardless of driver
        $prefix = SettingsManagerService::CACHE_PREFIX;
        $activeCorp = $this->settingsService->getActiveCorporation();

        // Clear common setting group caches
        $groups = [
            'general', 'tax_rates', 'pricing', 'features', 'dashboard',
            'tax_selector', 'exemptions', 'payment', 'notifications',
            'price_provider', 'janice_api_key', 'janice_market', 'janice_price_method',
        ];

        foreach ($groups as $group) {
            cache()->forget($prefix . 'global_' . $group);
            if ($activeCorp) {
                cache()->forget($prefix . $activeCorp . '_' . $group);
            }
        }

        Log::debug('Mining Manager: Settings cache cleared');
    }

    /**
     * Display settings page
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get corporation ID from request (if switching corporations)
        $corporationId = $request->input('corporation_id');

        // If corporation_id provided, set it as active context
        if ($corporationId) {
            $this->settingsService->setActiveCorporation((int)$corporationId);
        }

        // Check if corporation has custom settings
        $hasCustomSettings = false;
        $isFirstTimeSetup = false;
        if ($corporationId) {
            $hasCustomSettings = $this->settingsService->corporationHasCustomSettings((int)$corporationId);
            $isFirstTimeSetup = !$hasCustomSettings;
        }

        // Get all current settings (will use corporation context if set)
        $settings = [
            'general' => $this->settingsService->getGeneralSettings(),
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

        // Get available corporations from SeAT
        $corporations = $this->getAvailableCorporations();

        // Get webhooks for the settings page
        $webhooks = \MiningManager\Models\WebhookConfiguration::when($corporationId, function ($query) use ($corporationId) {
            return $query->forCorporation($corporationId);
        })->get();

        // Get wallet division names for the tax wallet division dropdown
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $walletDivisions = $this->getWalletDivisionNames($moonOwnerCorpId);

        // Notification tab data
        $mailScopeCharacters = $this->settingsService->getMailScopeCharacters();
        $allTokenCharacters = $this->settingsService->getAllTokenCharacters();
        $seatConnectorAvailable = Schema::hasTable('seat_connector_users');

        return view('mining-manager::settings.index', compact(
            'settings',
            'corporations',
            'corporationId',
            'hasCustomSettings',
            'isFirstTimeSetup',
            'webhooks',
            'walletDivisions',
            'mailScopeCharacters',
            'allTokenCharacters',
            'seatConnectorAvailable'
        ));
    }

    /**
     * Display configured corporations page
     *
     * @return \Illuminate\View\View
     */
    public function configuredCorporations()
    {
        // Get all corporation IDs that have custom settings
        $configuredCorpIds = DB::table('mining_manager_settings')
            ->whereNotNull('corporation_id')
            ->distinct()
            ->pluck('corporation_id');

        // Get corporation details
        $corporations = CorporationInfo::whereIn('corporation_id', $configuredCorpIds)
            ->get()
            ->map(function ($corp) {
                // Get key settings for this corporation
                $this->settingsService->setActiveCorporation($corp->corporation_id);

                $taxRates = $this->settingsService->getTaxRates();
                $taxSelector = $this->settingsService->getTaxSelector();

                return [
                    'corporation_id' => $corp->corporation_id,
                    'name' => $corp->name,
                    'ticker' => $corp->ticker,
                    'member_count' => $corp->member_count,
                    'settings_count' => DB::table('mining_manager_settings')
                        ->where('corporation_id', $corp->corporation_id)
                        ->count(),
                    // Moon ore tax rates by rarity
                    'moon_ore_r64_tax' => $taxRates['moon_ore']['r64'] ?? 0,
                    'moon_ore_r32_tax' => $taxRates['moon_ore']['r32'] ?? 0,
                    'moon_ore_r16_tax' => $taxRates['moon_ore']['r16'] ?? 0,
                    'moon_ore_r8_tax' => $taxRates['moon_ore']['r8'] ?? 0,
                    'moon_ore_r4_tax' => $taxRates['moon_ore']['r4'] ?? 0,
                    // Regular ore type tax rates
                    'ore_tax' => $taxRates['ore'] ?? 0,
                    'ice_tax' => $taxRates['ice'] ?? 0,
                    'gas_tax' => $taxRates['gas'] ?? 0,
                    'abyssal_ore_tax' => $taxRates['abyssal_ore'] ?? 0,
                    'triglavian_ore_tax' => $taxRates['triglavian_ore'] ?? 0,
                    // Tax selectors
                    'all_moon_ore' => $taxSelector['all_moon_ore'] ?? false,
                    'only_corp_moon_ore' => $taxSelector['only_corp_moon_ore'] ?? false,
                    'tax_regular_ore' => $taxSelector['ore'] ?? false,
                    'tax_ice' => $taxSelector['ice'] ?? false,
                    'tax_gas' => $taxSelector['gas'] ?? false,
                    'tax_abyssal_ore' => $taxSelector['abyssal_ore'] ?? false,
                    'tax_triglavian_ore' => $taxSelector['triglavian_ore'] ?? false,
                ];
            });

        return view('mining-manager::settings.configured_corporations', compact('corporations'));
    }

    /**
     * Get list of available corporations from SeAT
     *
     * @return \Illuminate\Support\Collection
     */
    private function getAvailableCorporations()
    {
        try {
            // Get corporations that the user has access to
            // via character affiliations
            return CorporationInfo::whereIn('corporation_id', function($query) {
                $query->select('corporation_id')
                    ->from('character_infos')
                    ->whereIn('character_id', function($subQuery) {
                        $subQuery->select('character_id')
                            ->from('user_settings')
                            ->where('user_id', auth()->user()->id);
                    });
            })
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();
        } catch (\Exception $e) {
            Log::error('Error loading corporations for settings', [
                'error' => $e->getMessage()
            ]);
            return collect();
        }
    }

    /**
     * Get wallet division names from corporation_divisions table.
     * Falls back to default EVE division names if not available.
     *
     * @param int|null $corporationId
     * @return array Division ID (1-7) => name
     */
    private function getWalletDivisionNames(?int $corporationId = null): array
    {
        $defaultNames = [
            1 => 'Master Wallet',
            2 => '2nd Wallet Division',
            3 => '3rd Wallet Division',
            4 => '4th Wallet Division',
            5 => '5th Wallet Division',
            6 => '6th Wallet Division',
            7 => '7th Wallet Division',
        ];

        if (!$corporationId) {
            return $defaultNames;
        }

        try {
            $divisions = DB::table('corporation_divisions')
                ->where('corporation_id', $corporationId)
                ->where('type', 'wallet')
                ->pluck('name', 'division')
                ->toArray();

            // Fill in missing divisions with defaults
            for ($i = 1; $i <= 7; $i++) {
                if (!isset($divisions[$i]) || empty($divisions[$i])) {
                    $divisions[$i] = $defaultNames[$i];
                }
            }

            ksort($divisions);
            return $divisions;
        } catch (\Exception $e) {
            return $defaultNames;
        }
    }

    /**
     * Update general settings
     * UPDATED: Now handles corporation_id from dropdown selection
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateGeneral(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Corporation Settings
            'corporation_id' => 'nullable|integer|exists:corporation_infos,corporation_id',
            'corporation_name' => 'nullable|string|max:255',
            'corporation_ticker' => 'nullable|string|max:5',
            'moon_owner_corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',

            // Note: timezone, date_format, time_format removed - always uses UTC for consistency

            // Display Settings
            'currency_decimals' => 'required|integer|min:0|max:8',
            'items_per_page' => 'required|integer|min:10|max:100',
            'compact_mode' => 'nullable|boolean',
            'show_character_portraits' => 'nullable|boolean',

            // Payment Settings
            'payment_match_tolerance' => 'nullable|integer|min:0|max:100000000',
            'payment_grace_period_hours' => 'nullable|integer|min:1|max:168',

            // Guest Miner Tax Rates (global, tied to Moon Owner Corporation)
            'guest_moon_ore_r64' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r32' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r16' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r8' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r4' => 'nullable|numeric|min:0|max:100',
            'guest_ore_tax' => 'nullable|numeric|min:0|max:100',
            'guest_ice_tax' => 'nullable|numeric|min:0|max:100',
            'guest_gas_tax' => 'nullable|numeric|min:0|max:100',
            'guest_abyssal_ore_tax' => 'nullable|numeric|min:0|max:100',
            'guest_triglavian_ore_tax' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = $validator->validated();

            // Set corporation context if provided
            $corporationId = $request->input('selected_corporation_id');
            if ($corporationId) {
                $this->settingsService->setActiveCorporation((int)$corporationId);
            }

            // If corporation_id is provided, fetch the name and ticker from SeAT
            if (isset($data['corporation_id']) && $data['corporation_id']) {
                $corporation = CorporationInfo::find($data['corporation_id']);
                if ($corporation) {
                    $data['corporation_name'] = $corporation->name;
                    $data['corporation_ticker'] = $corporation->ticker;
                }
            }

            // Convert boolean checkboxes (unchecked = not sent)
            $data['compact_mode'] = $request->has('compact_mode');
            $data['show_character_portraits'] = $request->has('show_character_portraits');
            $this->settingsService->updateGeneralSettings($data);
            $this->clearSettingsCache();

            // Redirect back with corporation_id to maintain context
            $redirectUrl = route('mining-manager.settings.index');
            if ($corporationId) {
                $redirectUrl .= '?corporation_id=' . $corporationId;
            }

            return redirect($redirectUrl)
                ->with('success', 'General settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating settings: ' . $e->getMessage());
        }
    }

    /**
     * Update notification settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateNotifications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // EVE Mail
            'evemail_enabled' => 'nullable|boolean',
            'evemail_sender_character_id' => 'nullable|integer',
            'evemail_sender_character_override' => 'nullable|integer',

            // Slack
            'slack_enabled' => 'nullable|boolean',
            'slack_webhook_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = [];
            $allTypes = SettingsManagerService::NOTIFICATION_TYPES;

            // Global per-type toggles (master switches)
            $enabledTypes = [];
            foreach ($allTypes as $type) {
                $enabledTypes[$type] = $request->has('notify_global_' . $type);
            }
            $data['enabled_types'] = $enabledTypes;

            // Per-type settings (role ping, user ping, show amount)
            $typeSettings = [];
            foreach ($allTypes as $type) {
                $typeSettings[$type] = [
                    'ping_role' => $request->has("type_{$type}_ping_role"),
                    'role_id' => $request->input("type_{$type}_role_id") ?: null,
                    'ping_user' => $request->has("type_{$type}_ping_user"),
                    'show_amount' => $request->has("type_{$type}_show_amount"),
                ];
            }
            $data['type_settings'] = $typeSettings;

            // EVE Mail
            $data['evemail_enabled'] = $request->has('evemail_enabled');
            $data['evemail_sender_character_id'] = $request->input('evemail_sender_character_id');
            $data['evemail_sender_character_override'] = $request->input('evemail_sender_character_override');

            // Build EVE mail types array from individual checkboxes
            $evemailTypes = [];
            foreach ($allTypes as $type) {
                $evemailTypes[$type] = $request->has('evemail_type_' . $type);
            }
            $data['evemail_types'] = $evemailTypes;

            // Slack
            $data['slack_enabled'] = $request->has('slack_enabled');
            $data['slack_webhook_url'] = $request->input('slack_webhook_url', '');

            // Build Slack types array
            $slackTypes = [];
            foreach ($allTypes as $type) {
                $slackTypes[$type] = $request->has('slack_type_' . $type);
            }
            $data['slack_types'] = $slackTypes;

            // Legacy discord pinging — derive from per-type settings
            // If any tax type has ping_user enabled, consider pinging enabled
            $data['discord_pinging_enabled'] = collect(['tax_reminder', 'tax_invoice', 'tax_overdue'])
                ->contains(fn ($t) => $typeSettings[$t]['ping_user'] ?? false);
            $data['discord_ping_show_amount'] = $typeSettings['tax_reminder']['show_amount'] ?? true;

            $this->settingsService->updateNotificationSettings($data);
            $this->clearSettingsCache();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Notification settings updated successfully')
                ->withFragment('notifications');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating notification settings: ' . $e->getMessage());
        }
    }

    /**
     * Update tax rates
     * COMPLETELY REWRITTEN: Now handles moon ore rarity rates and all new field names
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateTaxRates(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Moon Ore Rarity Tax Rates
            'moon_ore_r64' => 'required|numeric|min:0|max:100',
            'moon_ore_r32' => 'required|numeric|min:0|max:100',
            'moon_ore_r16' => 'required|numeric|min:0|max:100',
            'moon_ore_r8' => 'required|numeric|min:0|max:100',
            'moon_ore_r4' => 'required|numeric|min:0|max:100',

            // Regular Ore Type Tax Rates
            'ore_tax' => 'required|numeric|min:0|max:100',
            'ice_tax' => 'required|numeric|min:0|max:100',
            'gas_tax' => 'required|numeric|min:0|max:100',
            'abyssal_ore_tax' => 'required|numeric|min:0|max:100',
            'triglavian_ore_tax' => 'required|numeric|min:0|max:100',

            // Guest Miner Tax Settings — moved to General Settings tab.
            // Kept as nullable for backward compatibility if submitted from legacy forms.
            'guest_moon_ore_r64' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r32' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r16' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r8' => 'nullable|numeric|min:0|max:100',
            'guest_moon_ore_r4' => 'nullable|numeric|min:0|max:100',
            'guest_ore_tax' => 'nullable|numeric|min:0|max:100',
            'guest_ice_tax' => 'nullable|numeric|min:0|max:100',
            'guest_gas_tax' => 'nullable|numeric|min:0|max:100',
            'guest_abyssal_ore_tax' => 'nullable|numeric|min:0|max:100',
            'guest_triglavian_ore_tax' => 'nullable|numeric|min:0|max:100',

            // Tax Exemption Settings
            'exemption_enabled' => 'nullable|boolean',
            'exemption_threshold' => 'required|numeric|min:0',
            'minimum_tax_amount' => 'required|numeric|min:0',
            'minimum_tax_behavior' => 'required|in:exempt,enforce',
            'grace_period_days' => 'required|integer|min:0|max:365',

            // Tax Selector - Moon Ore (radio button group)
            'moon_ore_taxing' => 'required|in:all,corp,none',

            // Tax Selector - Other Ore Types (checkboxes)
            'tax_regular_ore' => 'nullable|boolean',
            'tax_ice' => 'nullable|boolean',
            'tax_gas' => 'nullable|boolean',
            'tax_abyssal_ore' => 'nullable|boolean',
            'tax_triglavian_ore' => 'nullable|boolean',

            // Tax Payment Method
            'tax_payment_method' => 'required|in:wallet',
            'tax_wallet_division' => 'required|integer|min:1|max:7',

            // Tax Code Settings
            'tax_code_prefix' => 'required|string|max:10',
            'tax_code_length' => 'required|integer|min:4|max:20',
            'auto_generate_tax_codes' => 'nullable|boolean',

            // Tax Period Settings
            'tax_calculation_period' => 'required|in:monthly,weekly,biweekly',
            'tax_payment_deadline_days' => 'required|integer|min:1|max:90',
            'send_tax_reminders' => 'nullable|boolean',
            'tax_reminder_days' => 'required|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = $validator->validated();

            // Set corporation context if provided
            $corporationId = $request->input('selected_corporation_id');
            if ($corporationId) {
                $this->settingsService->setActiveCorporation((int)$corporationId);
            }

            // Convert moon_ore_taxing radio button to boolean flags
            $moonOreSelector = $data['moon_ore_taxing'];
            $data['all_moon_ore'] = ($moonOreSelector === 'all');
            $data['only_corp_moon_ore'] = ($moonOreSelector === 'corp');
            $data['no_moon_ore'] = ($moonOreSelector === 'none');

            // Convert ore type checkboxes to booleans (unchecked = not sent)
            $data['tax_regular_ore'] = $request->has('tax_regular_ore');
            $data['tax_ice'] = $request->has('tax_ice');
            $data['tax_gas'] = $request->has('tax_gas');
            $data['tax_abyssal_ore'] = $request->has('tax_abyssal_ore');
            $data['tax_triglavian_ore'] = $request->has('tax_triglavian_ore');

            // Convert exemption_enabled checkbox
            $data['exemption_enabled'] = $request->has('exemption_enabled');

            // Convert other checkboxes
            $data['auto_generate_tax_codes'] = $request->has('auto_generate_tax_codes');
            $data['send_tax_reminders'] = $request->has('send_tax_reminders');

            // Update all settings via service
            $this->settingsService->updateTaxRates($data);
            $this->clearSettingsCache();

            // Redirect back with corporation_id to maintain context
            $redirectUrl = route('mining-manager.settings.index');
            if ($corporationId) {
                $redirectUrl .= '?corporation_id=' . $corporationId;
            }

            return redirect($redirectUrl)
                ->with('success', 'Tax rates updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating tax rates: ' . $e->getMessage());
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
            // Price provider settings
            'price_provider' => 'required|in:seat,fuzzwork,janice,manager-core',
            'price_type' => 'required|in:sell,buy,average',
            'cache_duration' => 'required|integer|min:1|max:1440',
            'fallback_to_jita' => 'nullable|boolean',
            
            // Janice-specific settings
            'janice_api_key' => 'nullable|string|max:255',
            'janice_market' => 'nullable|in:jita,amarr',
            'janice_price_method' => 'nullable|in:buy,sell,split',

            // Manager Core-specific settings
            'manager_core_market' => 'nullable|string|max:50',
            'manager_core_variant' => 'nullable|in:min,max,avg,median,percentile',
            
            // Refining settings
            'use_refined_value' => 'nullable|boolean',
            'refining_efficiency' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $validator->validated();

            // Check if switching away from Manager Core — unsubscribe if so
            $previousProvider = $this->settingsService->getSetting('price_provider', 'seat');
            $newProvider = $data['price_provider'] ?? 'seat';

            if ($previousProvider === 'manager-core' && $newProvider !== 'manager-core') {
                try {
                    $priceProviderService = app(\MiningManager\Services\Pricing\PriceProviderService::class);
                    $count = $priceProviderService->unsubscribeFromManagerCore();
                    Log::info("Mining Manager: Unsubscribed {$count} types from Manager Core (switched to {$newProvider})");
                } catch (\Exception $unsubEx) {
                    Log::warning('Mining Manager: Failed to unsubscribe from Manager Core: ' . $unsubEx->getMessage());
                }
            }

            // Store price provider and Janice settings via settings service
            // These use top-level keys (not 'pricing.' prefix) to match getPricingSettings() reads
            if (isset($data['price_provider'])) {
                $this->settingsService->updateSetting('price_provider', $data['price_provider'], 'string');
            }
            if (isset($data['janice_api_key'])) {
                $this->settingsService->updateSetting('janice_api_key', $data['janice_api_key'], 'string');
            }
            if (isset($data['janice_market'])) {
                $this->settingsService->updateSetting('janice_market', $data['janice_market'], 'string');
            }
            if (isset($data['janice_price_method'])) {
                $this->settingsService->updateSetting('janice_price_method', $data['janice_price_method'], 'string');
            }

            // Manager Core-specific settings
            if (isset($data['manager_core_market'])) {
                $this->settingsService->updateSetting('manager_core_market', $data['manager_core_market'], 'string');
            }
            if (isset($data['manager_core_variant'])) {
                $this->settingsService->updateSetting('manager_core_variant', $data['manager_core_variant'], 'string');
            }

            // Auto-subscribe type IDs to Manager Core when selected as provider
            if (($data['price_provider'] ?? '') === 'manager-core') {
                try {
                    $priceProviderService = app(\MiningManager\Services\Pricing\PriceProviderService::class);
                    $market = $data['manager_core_market'] ?? 'jita';
                    $count = $priceProviderService->subscribeToManagerCore($market);
                    Log::info("Mining Manager: Auto-subscribed {$count} type IDs to Manager Core");
                } catch (\Exception $subEx) {
                    Log::warning('Mining Manager: Failed to subscribe to Manager Core: ' . $subEx->getMessage());
                }
            }

            // Update other pricing settings via service (these use 'pricing.' prefix)
            $this->settingsService->updatePricingSettings([
                'price_type' => $data['price_type'],
                'cache_duration' => $data['cache_duration'],
                'fallback_to_jita' => $request->has('fallback_to_jita'),
                // Refining settings
                'use_refined_value' => $request->has('use_refined_value'),
                'refining_efficiency' => $data['refining_efficiency'],
                // Manager Core settings
                'manager_core_market' => $data['manager_core_market'] ?? 'jita',
                'manager_core_variant' => $data['manager_core_variant'] ?? 'min',
            ]);

            // Clear all settings + price caches
            $this->clearSettingsCache();
            try {
                cache()->tags(['mining-manager', 'prices'])->flush();
                cache()->tags(['mining-manager', 'moon-values'])->flush();
            } catch (\Exception $cacheException) {
                // File/database cache driver doesn't support tags - acceptable
                Log::debug('Mining Manager: Cache driver does not support tags, skipping tag-based flush');
            }

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Pricing settings updated successfully. Moon values will be recalculated on next view.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating pricing settings: ' . $e->getMessage());
        }
    }

    /**
     * Update dashboard settings
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateDashboard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dashboard_leaderboard_corporation_filter' => 'required|in:all,specific',
            'dashboard_leaderboard_corporation_ids' => 'nullable|array',
            'dashboard_leaderboard_corporation_ids.*' => 'integer|exists:corporation_infos,corporation_id',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = $validator->validated();

            // Set corporation context if provided
            $corporationId = $request->input('selected_corporation_id');
            if ($corporationId) {
                $this->settingsService->setActiveCorporation((int)$corporationId);
            }

            // Store dashboard settings via settings service (respects corporation context)
            $this->settingsService->updateSetting(
                'dashboard_leaderboard_corporation_filter',
                $data['dashboard_leaderboard_corporation_filter']
            );

            // Store corporation IDs as JSON
            $corpIds = ($data['dashboard_leaderboard_corporation_filter'] === 'specific')
                ? ($data['dashboard_leaderboard_corporation_ids'] ?? [])
                : [];
            $this->settingsService->updateSetting(
                'dashboard_leaderboard_corporation_ids',
                json_encode($corpIds),
                'json'
            );

            // Clear all settings caches
            $this->clearSettingsCache();

            // Redirect back with corporation_id to maintain context
            $redirectUrl = route('mining-manager.settings.index');
            if ($corporationId) {
                $redirectUrl .= '?corporation_id=' . $corporationId;
            }

            return redirect($redirectUrl)
                ->with('success', 'Dashboard settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating dashboard settings: ' . $e->getMessage());
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
        $validator = Validator::make($request->all(), [
            // Number inputs
            'extraction_notification_hours' => 'required|integer|min:1|max:168',
            'ledger_retention_days' => 'required|integer|min:30|max:3650',
            'tax_record_retention_days' => 'required|integer|min:90|max:3650',

            // Checkboxes (nullable because unchecked = not sent)
            'enable_tax_tracking' => 'nullable|boolean',
            'enable_ledger_tracking' => 'nullable|boolean',
            'enable_analytics' => 'nullable|boolean',
            'enable_reports' => 'nullable|boolean',
            'enable_events' => 'nullable|boolean',
            'allow_event_creation' => 'nullable|boolean',
            'auto_track_event_participation' => 'nullable|boolean',
            'enable_moon_tracking' => 'nullable|boolean',
            'track_moon_compositions' => 'nullable|boolean',
            'calculate_moon_value' => 'nullable|boolean',
            'notify_extraction_ready' => 'nullable|boolean',
            'allow_public_stats' => 'nullable|boolean',
            'allow_member_leaderboard' => 'nullable|boolean',
            'show_character_names' => 'nullable|boolean',
            'allow_export_data' => 'nullable|boolean',
            'auto_process_ledger' => 'nullable|boolean',
            'auto_calculate_taxes' => 'nullable|boolean',
            'auto_generate_invoices' => 'nullable|boolean',
            'verify_wallet_transactions' => 'nullable|boolean',
            'auto_cleanup_old_data' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = $validator->validated();

            // Build features array: checkboxes use $request->has(), numbers use validated data
            $features = [
                // Core features
                'enable_tax_tracking' => $request->has('enable_tax_tracking'),
                'enable_ledger_tracking' => $request->has('enable_ledger_tracking'),
                'enable_analytics' => $request->has('enable_analytics'),
                'enable_reports' => $request->has('enable_reports'),

                // Events
                'enable_events' => $request->has('enable_events'),
                'allow_event_creation' => $request->has('allow_event_creation'),
                'auto_track_event_participation' => $request->has('auto_track_event_participation'),

                // Moon mining
                'enable_moon_tracking' => $request->has('enable_moon_tracking'),
                'track_moon_compositions' => $request->has('track_moon_compositions'),
                'calculate_moon_value' => $request->has('calculate_moon_value'),
                'notify_extraction_ready' => $request->has('notify_extraction_ready'),
                'extraction_notification_hours' => $data['extraction_notification_hours'],

                // Permissions & access
                'allow_public_stats' => $request->has('allow_public_stats'),
                'allow_member_leaderboard' => $request->has('allow_member_leaderboard'),
                'show_character_names' => $request->has('show_character_names'),
                'allow_export_data' => $request->has('allow_export_data'),

                // Automation
                'auto_process_ledger' => $request->has('auto_process_ledger'),
                'auto_calculate_taxes' => $request->has('auto_calculate_taxes'),
                'auto_generate_invoices' => $request->has('auto_generate_invoices'),
                'verify_wallet_transactions' => $request->has('verify_wallet_transactions'),

                // Data retention
                'ledger_retention_days' => $data['ledger_retention_days'],
                'tax_record_retention_days' => $data['tax_record_retention_days'],
                'auto_cleanup_old_data' => $request->has('auto_cleanup_old_data'),
            ];

            $this->settingsService->updateFeatureFlags($features);
            $this->clearSettingsCache();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Feature settings updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
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
            try {
                cache()->tags(['mining-manager'])->flush();
            } catch (\Exception $cacheException) {
                \Log::debug('Mining Manager: Cache driver does not support tags, skipping tag-based flush');
            }

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'All caches cleared successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error clearing cache: ' . $e->getMessage());
        }
    }

    /**
     * Display help page
     *
     * @return \Illuminate\View\View
     */
    public function help()
    {
        return view('mining-manager::help.index');
    }

    /**
     * Search for corporations (Ajax endpoint for Select2)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchCorporations(Request $request)
    {
        $search = $request->input('search', '');
        
        try {
            $corporations = CorporationInfo::whereIn('corporation_id', function($query) {
                $query->select('corporation_id')
                    ->from('character_infos')
                    ->whereIn('character_id', function($subQuery) {
                        $subQuery->select('character_id')
                            ->from('user_settings')
                            ->where('user_id', auth()->user()->id);
                    });
            })
            ->where(function($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('ticker', 'LIKE', "%{$search}%");
            })
            ->select('corporation_id', 'name', 'ticker')
            ->limit(20)
            ->orderBy('name')
            ->get();

            return response()->json($corporations);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to search corporations: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // WEBHOOK MANAGEMENT METHODS
    // ============================================================================

    /**
     * Get all webhooks (Ajax)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWebhooks(Request $request)
    {
        $corporationId = $request->input('corporation_id');

        $webhooks = \MiningManager\Models\WebhookConfiguration::when($corporationId, function ($query) use ($corporationId) {
            return $query->forCorporation($corporationId);
        })->get();

        return response()->json([
            'success' => true,
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Get single webhook by ID (Ajax)
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWebhook($id)
    {
        try {
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($id);

            // Include sensitive webhook_url in response
            $webhook->makeVisible(['webhook_url']);

            return response()->json([
                'success' => true,
                'webhook' => $webhook,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook not found',
            ], 404);
        }
    }

    /**
     * Store new webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeWebhook(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|in:discord,slack,custom',
            'webhook_url' => 'required|url',
            'notify_theft_detected' => 'nullable|boolean',
            'notify_critical_theft' => 'nullable|boolean',
            'notify_active_theft' => 'nullable|boolean',
            'notify_incident_resolved' => 'nullable|boolean',
            'notify_moon_arrival' => 'nullable|boolean',
            'notify_jackpot_detected' => 'nullable|boolean',
            'notify_event_created' => 'nullable|boolean',
            'notify_event_started' => 'nullable|boolean',
            'notify_event_completed' => 'nullable|boolean',
            'notify_tax_generated' => 'nullable|boolean',
            'notify_tax_announcement' => 'nullable|boolean',
            'notify_tax_reminder' => 'nullable|boolean',
            'notify_tax_invoice' => 'nullable|boolean',
            'notify_tax_overdue' => 'nullable|boolean',
            'notify_report_generated' => 'nullable|boolean',
            'discord_role_id' => 'nullable|string|max:255',
            'discord_username' => 'nullable|string|max:255',
            'slack_channel' => 'nullable|string|max:255',
            'slack_username' => 'nullable|string|max:255',
            'custom_payload_template' => 'nullable|string',
            'corporation_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();

            // Convert checkboxes to booleans (boolean() checks actual value, not just key existence)
            $data['notify_theft_detected'] = $request->boolean('notify_theft_detected');
            $data['notify_critical_theft'] = $request->boolean('notify_critical_theft');
            $data['notify_active_theft'] = $request->boolean('notify_active_theft');
            $data['notify_incident_resolved'] = $request->boolean('notify_incident_resolved');
            $data['notify_moon_arrival'] = $request->boolean('notify_moon_arrival');
            $data['notify_jackpot_detected'] = $request->boolean('notify_jackpot_detected');
            $data['notify_event_created'] = $request->boolean('notify_event_created');
            $data['notify_event_started'] = $request->boolean('notify_event_started');
            $data['notify_event_completed'] = $request->boolean('notify_event_completed');
            $data['notify_tax_generated'] = $request->boolean('notify_tax_generated');
            $data['notify_tax_announcement'] = $request->boolean('notify_tax_announcement');
            $data['notify_tax_reminder'] = $request->boolean('notify_tax_reminder');
            $data['notify_tax_invoice'] = $request->boolean('notify_tax_invoice');
            $data['notify_tax_overdue'] = $request->boolean('notify_tax_overdue');
            $data['notify_report_generated'] = $request->boolean('notify_report_generated');

            $webhook = \MiningManager\Models\WebhookConfiguration::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Webhook created successfully',
                'webhook' => $webhook,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update existing webhook
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateWebhook(Request $request, $id)
    {
        try {
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'type' => 'required|in:discord,slack,custom',
                'webhook_url' => 'required|url',
                'notify_theft_detected' => 'nullable|boolean',
                'notify_critical_theft' => 'nullable|boolean',
                'notify_active_theft' => 'nullable|boolean',
                'notify_incident_resolved' => 'nullable|boolean',
                'notify_moon_arrival' => 'nullable|boolean',
                'notify_jackpot_detected' => 'nullable|boolean',
                'notify_event_created' => 'nullable|boolean',
                'notify_event_started' => 'nullable|boolean',
                'notify_event_completed' => 'nullable|boolean',
                'notify_tax_generated' => 'nullable|boolean',
                'notify_tax_announcement' => 'nullable|boolean',
                'notify_tax_reminder' => 'nullable|boolean',
                'notify_tax_invoice' => 'nullable|boolean',
                'notify_tax_overdue' => 'nullable|boolean',
                'notify_report_generated' => 'nullable|boolean',
                'discord_role_id' => 'nullable|string|max:255',
                'discord_username' => 'nullable|string|max:255',
                'slack_channel' => 'nullable|string|max:255',
                'slack_username' => 'nullable|string|max:255',
                'custom_payload_template' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Convert checkboxes to booleans (boolean() checks actual value, not just key existence)
            $data['notify_theft_detected'] = $request->boolean('notify_theft_detected');
            $data['notify_critical_theft'] = $request->boolean('notify_critical_theft');
            $data['notify_active_theft'] = $request->boolean('notify_active_theft');
            $data['notify_incident_resolved'] = $request->boolean('notify_incident_resolved');
            $data['notify_moon_arrival'] = $request->boolean('notify_moon_arrival');
            $data['notify_jackpot_detected'] = $request->boolean('notify_jackpot_detected');
            $data['notify_event_created'] = $request->boolean('notify_event_created');
            $data['notify_event_started'] = $request->boolean('notify_event_started');
            $data['notify_event_completed'] = $request->boolean('notify_event_completed');
            $data['notify_tax_generated'] = $request->boolean('notify_tax_generated');
            $data['notify_tax_announcement'] = $request->boolean('notify_tax_announcement');
            $data['notify_tax_reminder'] = $request->boolean('notify_tax_reminder');
            $data['notify_tax_invoice'] = $request->boolean('notify_tax_invoice');
            $data['notify_tax_overdue'] = $request->boolean('notify_tax_overdue');
            $data['notify_report_generated'] = $request->boolean('notify_report_generated');

            $webhook->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Webhook updated successfully',
                'webhook' => $webhook,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle webhook enabled status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleWebhook(Request $request, $id)
    {
        try {
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($id);

            $webhook->is_enabled = !$webhook->is_enabled;
            $webhook->save();

            return response()->json([
                'success' => true,
                'message' => $webhook->is_enabled ? 'Webhook enabled' : 'Webhook disabled',
                'is_enabled' => $webhook->is_enabled,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error toggling webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test webhook
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function testWebhook($id)
    {
        try {
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($id);

            $webhookService = app(\MiningManager\Services\Notification\WebhookService::class);
            $result = $webhookService->testWebhook($webhook);

            if ($result['success']) {
                $webhook->recordSuccess();
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification sent successfully!',
                ]);
            } else {
                $webhook->recordFailure($result['error'] ?? 'Unknown error');
                return response()->json([
                    'success' => false,
                    'message' => 'Test failed: ' . ($result['error'] ?? 'Unknown error'),
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error testing webhook: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete webhook
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteWebhook($id)
    {
        try {
            $webhook = \MiningManager\Models\WebhookConfiguration::findOrFail($id);
            $webhook->delete();

            return response()->json([
                'success' => true,
                'message' => 'Webhook deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting webhook: ' . $e->getMessage(),
            ], 500);
        }
    }
}
