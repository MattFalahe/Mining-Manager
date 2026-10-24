<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Configuration\SettingsTransfer;
use MiningManager\Services\DiscordRoleResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use MiningManager\Services\Character\AffiliationResolutionService;
use MiningManager\Services\Character\CharacterNames;
use MiningManager\Services\Tax\TaxPeriodHelper;
use Carbon\Carbon;

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
        // Whether the Balances tab shows depends on a feature flag, and its
        // visibility answer is cached for five minutes. Without dropping it
        // here, turning upfront payments on would leave the operator staring
        // at an unchanged tab bar wondering what they did wrong.
        cache()->forget('mining_manager_balances_tab_visible');

        try {
            cache()->tags(['mining-manager'])->flush();
        } catch (\Exception $e) {
            // File/database cache driver doesn't support tags — clear individually
            Log::debug('Mining Manager: Cache driver does not support tags, clearing settings cache prefix');
        }

        $prefix = SettingsManagerService::CACHE_PREFIX;
        $activeCorp = $this->settingsService->getActiveCorporation();

        // Clear by the FULL dotted cache key, not by group name. The keys
        // SettingsManagerService writes look like
        // `<prefix>global_notifications.enabled_types` /
        // `<prefix>global_pricing.cache_duration`, so forgetting
        // `<prefix>global_<group>` would target non-existent keys and leave
        // the real entries alive for up to CACHE_DURATION (60 minutes) —
        // stale settings after every save.
        //
        // Now: enumerate the actual setting keys from the DB and forget
        // each. Both global rows (corporation_id IS NULL) and active-corp
        // rows. Per-key Cache::forget is portable across all drivers
        // (file/db/redis). The `updateSetting` method already does this
        // for single-key writes; this bulk-clear extends the same pattern
        // to all-keys-after-batch-save.
        try {
            $allKeys = \MiningManager\Models\Setting::query()
                ->where(function ($q) use ($activeCorp) {
                    $q->whereNull('corporation_id');
                    if ($activeCorp) {
                        $q->orWhere('corporation_id', $activeCorp);
                    }
                })
                ->pluck('key')
                ->unique();

            foreach ($allKeys as $key) {
                cache()->forget($prefix . 'global_' . $key);
                if ($activeCorp) {
                    cache()->forget($prefix . $activeCorp . '_' . $key);
                }
            }
        } catch (\Throwable $e) {
            // Defensive — settings table missing or query exploded. Logged
            // at warning so a real schema issue surfaces rather than silently
            // allowing stale-cache reads.
            Log::warning('Mining Manager: Settings cache clear (per-key) failed: ' . $e->getMessage());
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
        $settings = [
            'general' => $this->settingsService->getGeneralSettings(),
            'payments' => $this->settingsService->getPaymentSettings(),
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

        $usesTaxProfiles = $this->settingsService->usesTaxProfiles();
        $profiles = $this->taxProfiles();
        $periodChangeDate = app(TaxPeriodHelper::class)->nextSafeEffectiveDate();
        $addableCorporations = $corporations
            ->reject(fn ($corporation) => $this->settingsService->isConfiguredCorporation((int) $corporation->corporation_id))
            ->values();
        $overdueDays = (int) ($this->settingsService->getFeatureFlags()['theft_overdue_days'] ?? 15);

        $transferReport = app(SettingsTransfer::class)->storedReport();
        $reportCorporations = $transferReport
            ? CorporationInfo::whereIn('corporation_id', array_merge(
                $transferReport['configured'] ?? [],
                array_column($transferReport['writes'] ?? [], 'corporation_id'),
                array_column($transferReport['superseded'] ?? [], 'corporation_id')
            ))->get(['corporation_id', 'name', 'ticker'])->keyBy('corporation_id')
            : collect();
        // The rows migration 000039 cleared once settings were in profiles.
        $retiredRows = $transferReport && Schema::hasTable('mining_manager_settings_archive')
            ? DB::table('mining_manager_settings_archive')->where('reason', 'retired_settings')->count()
            : 0;

        // Get webhooks for the settings page
        $webhooks = \MiningManager\Models\WebhookConfiguration::all();

        // Corps available for per-webhook routing — tax program corp + any
        // corp with plugin settings. Used by the webhook create/edit modal's
        // "Assign to Corporation" selector.
        $webhookCorporations = $this->settingsService->getAllCorporations();

        // Get wallet division names for the tax wallet division dropdown
        $moonOwnerCorpId = $this->settingsService->getSetting('general.moon_owner_corporation_id');
        $walletDivisions = $this->getWalletDivisionNames($moonOwnerCorpId);

        // Notification tab data
        $mailScopeCharacters = $this->settingsService->getMailScopeCharacters();
        $allTokenCharacters = $this->settingsService->getAllTokenCharacters();
        $seatConnectorAvailable = Schema::hasTable('seat_connector_users');

        // Discord role provider detection — used by the per-notification-type
        // role-id picker buttons in the notifications tab. When at least one
        // provider is detected (SeAT Broadcast / SeAT Connector / legacy
        // warlof), each role-id input gets a "Pick from Discord" button +
        // collapsible inline picker. Source-of-truth implementation:
        // structure-manager/src/Services/DiscordRoleResolver.php (copied here
        // verbatim with namespace change).
        $roleProviderAvailable = DiscordRoleResolver::isAvailable();
        $roleProviderLabel     = DiscordRoleResolver::providerLabel();

        $taxedCorporations = $this->getTaxedCorporations();

        // Saved JSON-encoded under the json type, so it reads back as text.
        $picked = $settings['dashboard']['dashboard_leaderboard_corporation_ids'] ?? [];
        $picked = is_string($picked) ? json_decode($picked, true) : $picked;
        $leaderboardCorporations = $this->getLeaderboardCorporations(
            array_values(array_unique(array_filter(array_map('intval', (array) $picked))))
        );

        return view('mining-manager::settings.index', compact(
            'settings',
            'corporations',
            'webhooks',
            'webhookCorporations',
            'walletDivisions',
            'mailScopeCharacters',
            'allTokenCharacters',
            'seatConnectorAvailable',
            'roleProviderAvailable',
            'roleProviderLabel',
            'taxedCorporations',
            'leaderboardCorporations',
            'profiles',
            'periodChangeDate',
            'addableCorporations',
            'usesTaxProfiles',
            'overdueDays',
            'transferReport',
            'reportCorporations',
            'retiredRows'
        ));
    }

    /**
     * Every tax profile and what it holds, for the Tax profiles page: Global
     * first, then the Moon Owner, then the rest by name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function taxProfiles(): array
    {
        $moonOwner = (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        $ids = $this->settingsService->usesTaxProfiles() ? $this->settingsService->getHomeCorporationIds() : [];
        $names = CorporationInfo::whereIn('corporation_id', $ids)->get(['corporation_id', 'name', 'ticker'])->keyBy('corporation_id');

        usort($ids, fn (int $a, int $b) => [$a !== $moonOwner, strcasecmp($names[$a]->name ?? '', $names[$b]->name ?? '')]
            <=> [$b !== $moonOwner, strcasecmp($names[$b]->name ?? '', $names[$a]->name ?? '')]);

        $periods = app(TaxPeriodHelper::class);
        $profiles = [];
        foreach (array_merge([null], $ids) as $id) {
            $read = fn (string $key, $default = null) => $this->settingsService->getSettingForCorporation($key, $id, $default);
            $selector = $this->settingsService->taxSelectorFor($id);

            $profiles[] = [
                'key' => $id ?? 'global',
                'corporation_id' => $id,
                'name' => $id === null ? 'Global' : ($names[$id]->name ?? "Corporation {$id}"),
                'ticker' => $id === null ? null : ($names[$id]->ticker ?? null),
                'moon_owner' => $id !== null && $id === $moonOwner,
                'period' => $read('tax_rates.tax_calculation_period', 'monthly'),
                'pending' => $read('tax_rates.tax_calculation_period_pending'),
                'pending_from' => $read('tax_rates.tax_calculation_period_effective_from'),
                'change_today' => $periods->canChangePeriodToday($id),
                'prefix' => $read('tax_rates.tax_code_prefix', 'TAX-'),
                'rates' => $this->settingsService->taxRatesFor($id),
                'selector' => $selector,
                'moon' => !empty($selector['no_moon_ore']) ? 'none' : (!empty($selector['only_corp_moon_ore']) ? 'ours' : (($selector['all_moon_ore'] ?? true) ? 'all' : 'none')),
            ];
        }

        return $profiles;
    }

    /**
     * Save the Billing page.
     */
    public function updateBilling(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exemption_enabled' => 'nullable|boolean',
            'exemption_threshold' => 'required|numeric|min:0',
            'minimum_tax_amount' => 'required|numeric|min:0',
            'minimum_tax_behavior' => 'required|in:exempt,enforce',
            'tax_payment_deadline_days' => 'required|integer|min:1|max:90',
            'payment_grace_period_hours' => 'required|integer|min:1|max:168',
            'payment_overdue_paid_threshold_pct' => 'required|numeric|min:0|max:100',
            'send_tax_reminders' => 'nullable|boolean',
            'tax_reminder_days' => 'required|integer|min:1|max:30',
            'tax_code_length' => 'required|integer|in:6,8,10,12',
            'grace_period_days' => 'required|integer|min:0|max:365',
            'tax_code_expiration_buffer' => 'required|integer|min:0|max:365',
            'auto_generate_tax_codes' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return redirect(route('mining-manager.settings.index') . '#billing')
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();
        $save = fn (string $key, $value, string $type) => $this->settingsService->updateGlobalSetting($key, $value, $type);

        DB::transaction(function () use ($data, $request, $save) {
            $save('exemptions.enabled', $request->boolean('exemption_enabled'), 'boolean');
            $save('exemptions.threshold', (float) $data['exemption_threshold'], 'float');
            $save('payment.minimum_tax_amount', (float) $data['minimum_tax_amount'], 'float');
            $save('payment.minimum_tax_behavior', $data['minimum_tax_behavior'], 'string');
            $save('tax_rates.tax_payment_deadline_days', (int) $data['tax_payment_deadline_days'], 'integer');
            $save('payment.grace_period_hours', (int) $data['payment_grace_period_hours'], 'integer');
            $save('payment.overdue_paid_threshold_pct', (float) $data['payment_overdue_paid_threshold_pct'], 'float');
            $save('tax_rates.send_tax_reminders', $request->boolean('send_tax_reminders'), 'boolean');
            $save('tax_rates.tax_reminder_days', (int) $data['tax_reminder_days'], 'integer');
            $save('tax_rates.tax_code_length', (int) $data['tax_code_length'], 'integer');
            $save('exemptions.grace_period_days', (int) $data['grace_period_days'], 'integer');
            $save('tax_rates.tax_code_expiration_buffer', (int) $data['tax_code_expiration_buffer'], 'integer');
            $save('tax_rates.auto_generate_tax_codes', $request->boolean('auto_generate_tax_codes'), 'boolean');
        });

        $this->clearSettingsCache();

        return redirect(route('mining-manager.settings.index') . '#billing')
            ->with('success', 'Billing saved.');
    }

    /**
     * Save the Payments page.
     */
    public function updatePayments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tax_wallet_division' => 'required|integer|min:1|max:7',
            'payment_match_tolerance' => 'required|integer|min:0|max:100000000',
            'payment_auto_match_payments' => 'nullable|boolean',
            'payment_accept_alt_characters' => 'nullable|boolean',
            'payment_cascade_remainder' => 'nullable|boolean',
            'payment_hold_surplus_as_credit' => 'nullable|boolean',
            'enable_upfront_payments' => 'nullable|boolean',
            'payment_upfront_keyword' => 'nullable|string|max:32',
            'payment_refund_keyword' => 'nullable|string|max:32',
        ]);

        if ($validator->fails()) {
            return redirect(route('mining-manager.settings.index') . '#payments')
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();
        $upfront = trim((string) ($data['payment_upfront_keyword'] ?? ''));
        $refund = trim((string) ($data['payment_refund_keyword'] ?? ''));

        // Codes and both keywords are read from the same transfer reason. If
        // one contains another, a payment could read as either, and which one
        // won would depend on matching order: nothing anyone could diagnose.
        $taken = $this->settingsService->taxCodePrefixes();
        foreach (array_filter(['upfront payment' => $upfront, 'refund' => $refund]) as $which => $keyword) {
            $others = $which === 'refund' ? array_merge($taken, array_filter([$upfront])) : $taken;

            foreach ($others as $other) {
                if (stripos($keyword, $other) !== false || stripos($other, $keyword) !== false) {
                    return redirect(route('mining-manager.settings.index') . '#payments')
                        ->withInput()
                        ->with('error', "The {$which} keyword {$keyword} overlaps {$other}. Both are read from the transfer reason, so pick something clearly different.");
                }
            }
        }

        $save = fn (string $key, $value, string $type) => $this->settingsService->updateGlobalSetting($key, $value, $type);

        DB::transaction(function () use ($data, $request, $save, $upfront, $refund) {
            $save('tax_rates.tax_wallet_division', (int) $data['tax_wallet_division'], 'integer');
            $save('payment.match_tolerance', (int) $data['payment_match_tolerance'], 'integer');
            $save('payment.auto_match_payments', $request->boolean('payment_auto_match_payments'), 'boolean');
            $save('payment.accept_alt_characters', $request->boolean('payment_accept_alt_characters'), 'boolean');
            $save('payment.cascade_remainder', $request->boolean('payment_cascade_remainder'), 'boolean');
            $save('payment.hold_surplus_as_credit', $request->boolean('payment_hold_surplus_as_credit'), 'boolean');
            $save('features.enable_upfront_payments', $request->boolean('enable_upfront_payments'), 'boolean');
            $save('payment.upfront_keyword', $upfront, 'string');
            $save('payment.refund_keyword', $refund, 'string');
        });

        $this->clearSettingsCache();

        return redirect(route('mining-manager.settings.index') . '#payments')
            ->with('success', 'Payments saved.');
    }

    /**
     * Save the Setup page's Moon Owner Corporation.
     */
    public function updateSetup(Request $request)
    {
        $validated = $request->validate([
            'moon_owner_corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
        ]);

        $new = (int) $validated['moon_owner_corporation_id'];
        $old = (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        $note = '';

        if ($new !== $old) {
            // A Moon Owner is always configured. One that was not yet starts
            // with the outgoing Moon Owner's profile, not the defaults, so
            // its members' tax does not change with the switch.
            $stored = array_map('intval', (array) $this->settingsService->getSettingForCorporation(SettingsTransfer::CONFIGURED_KEY, null, []));
            if ($this->settingsService->usesTaxProfiles() && !in_array($new, $stored, true)) {
                $this->settingsService->configureCorporation($new, $old);
                $note = $this->heldPeriodNote($new);
            }

            $this->settingsService->updateGlobalSetting('general.moon_owner_corporation_id', $new, 'integer');
            $this->clearSettingsCache();
        }

        return redirect(route('mining-manager.settings.index') . '#setup')
            ->with('success', 'Moon Owner Corporation saved.' . $note);
    }

    /**
     * Configure a corporation, starting from a copy of the Moon Owner's
     * profile.
     */
    public function addCorporation(Request $request)
    {
        $validated = $request->validate([
            'corporation_id' => 'required|integer|exists:corporation_infos,corporation_id',
        ]);
        $id = (int) $validated['corporation_id'];
        $name = CorporationInfo::find($id)->name ?? "Corporation {$id}";

        if (!$this->settingsService->usesTaxProfiles()) {
            return redirect(route('mining-manager.settings.index') . '#setup')
                ->with('error', 'Settings have not moved into tax profiles yet. The plugin\'s migrations do that when it updates.');
        }

        if ($this->settingsService->isConfiguredCorporation($id)) {
            return redirect(route('mining-manager.settings.index') . '#setup')
                ->with('success', "{$name} is already configured.");
        }

        $moonOwner = (int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        $this->settingsService->configureCorporation($id, $moonOwner);
        $this->clearSettingsCache();

        return redirect(route('mining-manager.settings.index') . '#profiles')
            ->with('success', "{$name} is configured. Its members are taxed by its own profile, a copy of the Moon Owner's, from now on." . $this->heldPeriodNote($id) . ' Change it under Tax profiles.');
    }

    /**
     * Stop treating a corporation's members as members.
     */
    public function removeCorporation(Request $request)
    {
        $validated = $request->validate([
            'corporation_id' => 'required|integer',
        ]);
        $id = (int) $validated['corporation_id'];
        $name = CorporationInfo::find($id)->name ?? "Corporation {$id}";

        if ($id === ((int) $this->settingsService->getSettingForCorporation('general.moon_owner_corporation_id', null))) {
            return redirect(route('mining-manager.settings.index') . '#setup')
                ->with('error', 'The Moon Owner Corporation is always configured. Choose another Moon Owner first.');
        }

        $this->settingsService->removeConfiguredCorporation($id);
        $this->clearSettingsCache();

        return redirect(route('mining-manager.settings.index') . '#setup')
            ->with('success', "{$name} is no longer configured. Its members are non-members from tomorrow, taxed by the Global profile. Their bills for " . Carbon::today()->format('F') . " still follow its profile, on its billing period and code prefix, so the month is billed on one period.");
    }

    /**
     * Save the Tax profiles page: every profile at once.
     */
    public function updateProfiles(Request $request)
    {
        if (!$this->settingsService->usesTaxProfiles()) {
            return redirect(route('mining-manager.settings.index') . '#profiles')
                ->with('error', 'Settings have not moved into tax profiles yet. The plugin\'s migrations do that when it updates.');
        }

        $keys = array_merge(['global'], array_map('strval', $this->settingsService->getHomeCorporationIds()));
        $others = ['ore', 'ice', 'gas', 'abyssal_ore', 'triglavian_ore'];

        $rules = ['apply_period_now' => 'nullable|boolean'];
        foreach ($keys as $key) {
            $rules["profiles.{$key}.period"] = 'required|in:monthly,biweekly';
            $rules["profiles.{$key}.prefix"] = 'required|string|max:10';
            $rules["profiles.{$key}.moon"] = 'required|in:all,ours,none';
            foreach (['r4', 'r8', 'r16', 'r32', 'r64'] as $rarity) {
                $rules["profiles.{$key}.rates.moon_ore.{$rarity}"] = 'required|numeric|min:0|max:100';
            }
            foreach ($others as $other) {
                $rules["profiles.{$key}.rates.{$other}"] = 'nullable|numeric|min:0|max:100';
            }
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect(route('mining-manager.settings.index') . '#profiles')
                ->withErrors($validator)
                ->withInput();
        }
        $validated = $validator->validated();

        // The keywords are read from the same transfer reason as the codes.
        $payments = $this->settingsService->getPaymentSettings();
        $keywords = array_filter([trim((string) ($payments['upfront_keyword'] ?? '')), trim((string) ($payments['refund_keyword'] ?? ''))]);
        foreach ($validated['profiles'] ?? [] as $values) {
            $prefix = trim((string) $values['prefix']);
            foreach ($keywords as $keyword) {
                if (stripos($prefix, $keyword) !== false || stripos($keyword, $prefix) !== false) {
                    return redirect(route('mining-manager.settings.index') . '#profiles')
                        ->withInput()
                        ->with('error', "The tax code prefix {$prefix} overlaps the payment keyword {$keyword}. Both are read from the transfer reason, so pick a prefix clearly different from it.");
                }
            }
        }

        $waiting = [];
        DB::transaction(function () use ($validated, $request, $others, &$waiting) {
            foreach ($validated['profiles'] ?? [] as $key => $values) {
                $rates = Arr::dot($values['rates'] ?? []);

                // An ore whose rate was not on the form, as Global's are not
                // while corporations are configured, keeps what it had.
                $taxed = [];
                foreach ($others as $other) {
                    if (array_key_exists($other, $rates)) {
                        $taxed[$other] = $request->boolean("profiles.{$key}.taxed.{$other}");
                    }
                }

                $profile = $key === 'global' ? null : (int) $key;
                $period = $this->settingsService->saveProfile($profile, [
                    'prefix' => trim((string) $values['prefix']),
                    'moon' => $values['moon'],
                    'rates' => array_filter($rates, fn ($rate) => $rate !== null && $rate !== ''),
                    'taxed' => $taxed,
                    'period' => $values['period'],
                ], $request->boolean('apply_period_now'));

                if ($period === 'queued' && $request->boolean('apply_period_now')) {
                    $waiting[] = $profile;
                }
            }
        });

        $this->clearSettingsCache();

        $message = 'Tax profiles saved.';
        if ($waiting) {
            $from = app(TaxPeriodHelper::class)->nextSafeEffectiveDate()->format('F j');
            $message .= count($waiting) === 1
                ? " {$this->profileNames($waiting)}'s new billing period starts on {$from}, not today."
                : " The new billing periods of {$this->profileNames($waiting)} start on {$from}, not today.";
            $message .= ' A period can only change on the day from the 3rd to the 15th of a month, while the profile has billed none of it.';
        }

        return redirect(route('mining-manager.settings.index') . '#profiles')
            ->with('success', $message);
    }

    /**
     * Tax profiles by name, as the Tax profiles page heads them: Global, or a
     * corporation's ticker.
     *
     * @param array<int, int|null> $profiles
     */
    private function profileNames(array $profiles): string
    {
        $tickers = CorporationInfo::whereIn('corporation_id', array_filter($profiles))->pluck('ticker', 'corporation_id');
        $names = array_map(
            fn (?int $profile) => $profile === null ? 'Global' : (isset($tickers[$profile]) ? "[{$tickers[$profile]}]" : "Corporation {$profile}"),
            array_values($profiles)
        );

        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : (string) reset($names);
    }

    /**
     * For a corporation just configured: when its bills keep the period its
     * members were billed on this month for now, and when they change.
     */
    private function heldPeriodNote(int $corporationId): string
    {
        $change = app(TaxPeriodHelper::class)->pendingPeriodChangeFor($corporationId);

        if ($change === null) {
            return '';
        }

        $label = fn (string $period) => $period === 'biweekly' ? 'twice a month' : 'monthly';

        return sprintf(
            ' Its bills go out %s until %s, as its members\' have this month, and %s from %s.',
            $label($change['current']),
            $change['effective_from']->copy()->subDay()->format('F j'),
            $label($change['pending']),
            $change['effective_from']->format('F j')
        );
    }

    /**
     * Corporations whose members have been billed mining tax, by name, for
     * the Outstanding Mining Tax corporation filter. A corporation already
     * picked stays on the list after its members' bills are gone.
     *
     * @return array<int, string> corporation_id => name
     */
    private function getTaxedCorporations(): array
    {
        $ids = [];

        try {
            $billed = fn () => DB::table('mining_taxes')->select('character_id')->distinct();

            // SeAT's affiliations first, the plugin's lookups for anyone SeAT
            // has no record of.
            $ids = DB::table('character_affiliations')
                ->whereIn('character_id', $billed())
                ->distinct()
                ->pluck('corporation_id')
                ->all();

            $ids = array_merge($ids, DB::table(AffiliationResolutionService::TABLE)
                ->whereIn('character_id', $billed())
                ->whereNotIn('character_id', DB::table('character_affiliations')->select('character_id'))
                ->whereNotNull('corporation_id')
                ->distinct()
                ->pluck('corporation_id')
                ->all());
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not list the corporations with tax bills: ' . $e->getMessage());
        }

        $picked = $this->settingsService->getTypeNotificationSettings('tax_outstanding_digest')['corporations'];
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge($ids, $picked)))));

        $names = app(CharacterNames::class)->corporationNames($ids);

        $corporations = [];
        foreach ($ids as $id) {
            $corporations[$id] = $names[$id] ?? "Corporation {$id}";
        }
        asort($corporations, SORT_NATURAL | SORT_FLAG_CASE);

        return $corporations;
    }

    /**
     * AJAX endpoint for the inline Discord role picker.
     *
     * Returns the merged + deduped role list from all detected providers
     * (SeAT Broadcast curated, SeAT Connector synced, legacy warlof). The
     * notifications tab JS calls this once per page load and caches the
     * response across all per-type pickers — so opening any of the 17
     * role-id pickers on the page incurs at most one round-trip total.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listDiscordRoles()
    {
        return response()->json([
            'available' => DiscordRoleResolver::isAvailable(),
            'label'     => DiscordRoleResolver::providerLabel(),
            'roles'     => DiscordRoleResolver::listRoles(),
        ]);
    }

    /**
     * Corporations the Setup page offers for the Moon Owner and for adding:
     * every player corporation a registered character is in, plus every
     * configured corporation, so a saved choice is always on the list.
     *
     * SeAT 5 keeps a character's corporation in character_affiliations and
     * who owns a character in refresh_tokens. Beware the older columns: a
     * subquery naming a column its table lacks takes it from the outer query
     * instead of failing, so a query on them runs and returns the wrong rows.
     *
     * @return \Illuminate\Support\Collection of corporation_id, name, ticker
     */
    private function getAvailableCorporations()
    {
        $ids = [];

        try {
            $ids = DB::table('character_affiliations')
                ->whereIn('character_id', DB::table('refresh_tokens')->whereNull('deleted_at')->select('character_id'))
                // NPC corporations: alts start in one, nobody runs a tax program from one.
                ->whereNotBetween('corporation_id', [1000000, 1999999])
                ->distinct()
                ->pluck('corporation_id')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not list the corporations of registered characters: ' . $e->getMessage());
        }

        $ids = array_values(array_unique(array_map('intval', array_merge($ids, $this->settingsService->getHomeCorporationIds()))));

        if (!$ids) {
            return collect();
        }

        try {
            return CorporationInfo::whereIn('corporation_id', $ids)
                ->select('corporation_id', 'name', 'ticker')
                ->orderBy('name')
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not load corporations for settings: ' . $e->getMessage());

            return collect();
        }
    }

    /**
     * The corporations the Top Miners leaderboard can be limited to, with how
     * many of their characters mined in the last 12 months, the longest
     * window the leaderboard shows.
     *
     * Only member corporations are offered: the moon owner and those with
     * their own settings. The leaderboard ranks members alone, so picking a
     * guest corporation would empty it rather than add anyone. A corporation
     * already picked stays on the list so it can be taken off again.
     *
     * Each character counts under the corporation they are in now, SeAT's
     * affiliations first and the plugin's lookups for anyone SeAT has no
     * record of, the same answer the dashboard filters on.
     *
     * @param array<int, int> $picked corporations saved in the filter
     * @return array<int, array{label: string, miners: int, picked: bool}> by corporation_id, sorted by name
     */
    private function getLeaderboardCorporations(array $picked): array
    {
        $since = Carbon::now()->subMonths(12)->startOfMonth()->toDateString();
        $corporationOf = [];

        try {
            $characterIds = DB::table('mining_ledger_daily_summaries')
                ->where('date', '>=', $since)
                ->distinct()
                ->pluck('character_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach (array_chunk($characterIds, 1000) as $chunk) {
                foreach (DB::table('character_affiliations')->whereIn('character_id', $chunk)->pluck('corporation_id', 'character_id') as $characterId => $corporationId) {
                    $corporationOf[(int) $characterId] = (int) $corporationId;
                }
            }

            $unplaced = array_values(array_diff($characterIds, array_keys($corporationOf)));
            foreach (app(AffiliationResolutionService::class)->known($unplaced) as $characterId => $row) {
                if (!empty($row->corporation_id)) {
                    $corporationOf[(int) $characterId] = (int) $row->corporation_id;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: could not list the corporations with mining for the leaderboard filter: ' . $e->getMessage());
        }

        $miners = array_intersect_key(
            array_count_values($corporationOf),
            array_flip(array_map('intval', $this->settingsService->getHomeCorporationIds()))
        );

        foreach ($picked as $corporationId) {
            $miners[$corporationId] = $miners[$corporationId] ?? 0;
        }

        if (!$miners) {
            return [];
        }

        $ids = array_keys($miners);
        $names = app(CharacterNames::class)->corporationNames($ids);

        try {
            $tickers = DB::table('corporation_infos')->whereIn('corporation_id', $ids)->pluck('ticker', 'corporation_id')->all();
        } catch (\Throwable $e) {
            $tickers = [];
        }

        $corporations = [];
        foreach ($miners as $corporationId => $count) {
            $name = $names[$corporationId] ?? "Corporation {$corporationId}";
            $ticker = $tickers[$corporationId] ?? null;

            $corporations[$corporationId] = [
                'label' => $ticker ? "[{$ticker}] {$name}" : $name,
                'miners' => $count,
                'picked' => in_array($corporationId, $picked, true),
            ];
        }

        uksort($corporations, fn ($a, $b) => strnatcasecmp($names[$a] ?? "Corporation {$a}", $names[$b] ?? "Corporation {$b}"));

        return $corporations;
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
            // HTTPS-only — Slack webhooks are always served at https://hooks.slack.com.
            // The starts_with rule blocks http://, file://, gopher://, ftp://, etc.,
            // closing the SSRF vector that http://127.0.0.1:port and similar internal
            // URLs would otherwise present. Symmetric with webhookValidationRules()
            // which gates the per-webhook URLs the same way.
            //
            // Conditional `required_if`: if the operator turned slack_enabled
            // ON, the URL must be present. Otherwise an admin can save with
            // slack_enabled=true and an empty URL, and every notification
            // dispatch returns "Slack webhook URL not configured" with no
            // save-time error pointing at the missing field.
            'slack_webhook_url' => [
                'nullable',
                'required_if:slack_enabled,1',
                'required_if:slack_enabled,true',
                'url',
                'starts_with:https://',
            ],

            // Metenox cargo full threshold — clamped to a sane range so an
            // operator can't accidentally disable the alert (0%) or set it
            // so tight the cron never observes a crossing (100% is unreachable
            // due to rounding). The scanner re-clamps server-side too, but
            // catching invalid values at save time gives the operator a
            // proper error rather than a silent floor/ceiling at runtime.
            'metenox_cargo_full_threshold_pct' => [
                'nullable',
                'numeric',
                'min:50',
                'max:99',
            ],
            'moon_scan_missing_daily' => [
                'nullable',
                'boolean',
            ],
            // Outstanding Mining Tax: whose bills the digest lists.
            'type_tax_outstanding_digest_corporations' => 'nullable|array',
            'type_tax_outstanding_digest_corporations.*' => 'integer|min:1',
            'moon_extraction_fastpoll_mode' => [
                'nullable',
                'in:auto,seat_native',
            ],
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

            // Each per-type map is updated in place, and only for the types
            // its list on the page shows. An unticked box is simply missing
            // from the request, so writing a type the page has no box for
            // would store it as off.

            // Global per-type toggles (master switches)
            $enabledTypes = $this->storedNotificationMap('enabled_types');
            foreach ($allTypes as $type) {
                $enabledTypes[$type] = $request->has('notify_global_' . $type);
            }
            $data['enabled_types'] = $enabledTypes;

            // Per-type settings (role ping, user ping, show amount)
            $typeSettings = $this->storedNotificationMap('type_settings');
            foreach ($allTypes as $type) {
                $typeSettings[$type] = [
                    'ping_role' => $request->has("type_{$type}_ping_role"),
                    'role_id' => $request->input("type_{$type}_role_id") ?: null,
                    'ping_user' => $request->has("type_{$type}_ping_user"),
                    'show_amount' => $request->has("type_{$type}_show_amount"),
                ];

                // A card with a corporation filter says so, as a list with
                // nothing picked is not sent at all.
                if ($request->has("type_{$type}_corporation_filter")) {
                    $typeSettings[$type]['corporations'] = array_values(array_unique(array_map('intval',
                        (array) $request->input("type_{$type}_corporations", []))));
                }
            }
            $data['type_settings'] = $typeSettings;

            // EVE Mail. The page has no per-type EVE Mail boxes, so
            // evemail_types is left as stored.
            $data['evemail_enabled'] = $request->has('evemail_enabled');
            $data['evemail_sender_character_id'] = $request->input('evemail_sender_character_id');
            $data['evemail_sender_character_override'] = $request->input('evemail_sender_character_override');

            // Slack
            $data['slack_enabled'] = $request->has('slack_enabled');
            $data['slack_webhook_url'] = $request->input('slack_webhook_url', '');

            $slackTypes = $this->storedNotificationMap('slack_types');
            foreach (SettingsManagerService::SLACK_NOTIFICATION_TYPES as $type) {
                $slackTypes[$type] = $request->has('slack_type_' . $type);
            }
            $data['slack_types'] = $slackTypes;

            // Metenox cargo full threshold (default 85). Operator-configurable
            // via the input below the metenox_cargo_full type card.
            $thresholdInput = $request->input('metenox_cargo_full_threshold_pct');
            $data['metenox_cargo_full_threshold_pct'] = ($thresholdInput !== null && $thresholdInput !== '')
                ? (float) $thresholdInput
                : 85;

            // Moon Scan Missing goes once per new reason; the daily list on top
            // of that is opt-in.
            $data['moon_scan_missing_daily'] = $request->boolean('moon_scan_missing_daily');

            // extraction_started detection mode (auto = Manager Core fast-poll
            // when present; seat_native = endpoint-driven cron pass).
            $fastpollMode = $request->input('moon_extraction_fastpoll_mode');
            $data['moon_extraction_fastpoll_mode'] = in_array($fastpollMode, ['auto', 'seat_native'], true)
                ? $fastpollMode
                : 'auto';

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
     * Save the Moon Planner page: how the planner spaces pulls, and when its
     * reminders go out. The values keep their notifications.* keys from when
     * they were part of the Notifications page.
     */
    public function updatePlanner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Moon Extraction Planner minimum gap (hours). Bounded [1, 168]
            // (1 week) — clusters tighter than an hour are nonsensical and a
            // gap wider than a week defeats staggering on weekly cadences.
            'min_extraction_gap_hours' => [
                'nullable',
                'integer',
                'min:1',
                'max:168',
            ],
            // How close two pulls on one refinery may be, entered in days and
            // kept in hours. 6 is the shortest extraction EVE allows, 56 the
            // longest.
            'min_refinery_spacing_days' => [
                'nullable',
                'numeric',
                'min:6',
                'max:56',
            ],
            // Moon Not Rescheduled: hours a drill sits idle after its chunk
            // arrives before the reminder goes, and between repeats. Up to two
            // weeks; past that the reminder has stopped being one.
            'moon_not_rescheduled_hours' => [
                'nullable',
                'integer',
                'min:1',
                'max:336',
            ],
            'moon_not_rescheduled_repeat' => [
                'nullable',
                'boolean',
            ],
            // Moons Need Planning: how many pulls each refinery should have
            // planned ahead, and how often the list goes out.
            'planned_ahead_target' => [
                'nullable',
                'integer',
                'min:1',
                'max:10',
            ],
            'schedule_needs_filling_hours' => [
                'nullable',
                'integer',
                'min:1',
                'max:336',
            ],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = [];

            // Minimum gap (hours) between two moons' arrivals.
            $gapInput = $request->input('min_extraction_gap_hours');
            $data['min_extraction_gap_hours'] = ($gapInput !== null && $gapInput !== '')
                ? (int) $gapInput
                : 24;

            // Time between pulls on one refinery: days on the form, hours kept,
            // so 7 days means a pull on the 1st at 17:00 is clear on the 8th
            // at 17:00.
            $spacingInput = $request->input('min_refinery_spacing_days');
            $data['min_refinery_spacing_hours'] = ($spacingInput !== null && $spacingInput !== '')
                ? (int) round((float) $spacingInput * 24)
                : \MiningManager\Services\Moon\MoonPlannerService::DEFAULT_REFINERY_SPACING_HOURS;

            // Moon Not Rescheduled reminder. The whole page is one form, so an
            // unticked repeat box is simply absent and reads as off.
            $idleInput = $request->input('moon_not_rescheduled_hours');
            $data['moon_not_rescheduled_hours'] = ($idleInput !== null && $idleInput !== '')
                ? (int) $idleInput
                : 48;
            $data['moon_not_rescheduled_repeat'] = $request->boolean('moon_not_rescheduled_repeat');

            // Moons Need Planning.
            $targetInput = $request->input('planned_ahead_target');
            $data['planned_ahead_target'] = ($targetInput !== null && $targetInput !== '')
                ? (int) $targetInput
                : 1;
            $everyInput = $request->input('schedule_needs_filling_hours');
            $data['schedule_needs_filling_hours'] = ($everyInput !== null && $everyInput !== '')
                ? (int) $everyInput
                : 24;

            $this->settingsService->updateNotificationSettings($data);
            $this->clearSettingsCache();

            return redirect()->route('mining-manager.settings.index')
                ->with('success', 'Moon Planner settings updated successfully')
                ->withFragment('planner');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error updating Moon Planner settings: ' . $e->getMessage());
        }
    }

    /**
     * A per-type notification map as it is stored now, for a save to update.
     *
     * @param string $key e.g. 'enabled_types'
     * @return array
     */
    private function storedNotificationMap(string $key): array
    {
        $stored = $this->settingsService->getSetting('notifications.' . $key, []);

        return is_array($stored) ? $stored : [];
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
            'fallback_provider' => 'nullable|in:none,fuzzwork,janice,manager-core',
            
            // Janice-specific settings
            'janice_api_key' => 'nullable|string|max:255',
            'janice_market' => 'nullable|in:jita,amarr',
            'janice_price_method' => 'nullable|in:buy,sell,split',

            // Manager Core-specific settings: market + price_type are now
            // configured centrally in MC's Pricing Preferences page, not
            // duplicated here. The form panel shows a read-only status
            // readout + deep-link instead. The local manager_core_market
            // setting is kept for the auto-subscribe call below (it's just
            // the bootstrap default — MC's preference wins on read).
            // manager_core_variant dropped entirely; hardcoded to 'min' in
            // CachePriceDataCommand because that's the only variant that
            // makes sense for tax + payout (lowest sell = real buy price).

            // Refining settings
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

            // Manager Core-specific settings are persisted via
            // updatePricingSettings() below (under the `pricing.` prefix)
            // — the canonical reader (SettingsManagerService::getPricingSettings)
            // looks them up there. Do NOT also write them unprefixed
            // (`manager_core_market` / `manager_core_variant`) — that creates
            // orphan rows nothing reads. The prefixed write later is the
            // single source of truth.

            // Auto-subscribe type IDs to Manager Core when selected as
            // provider, AND register MM's default preference into MC's
            // Pricing Preferences table. The preference is what MC's
            // pricing.priceForPlugin / pricesForPlugin reads back; without
            // this seed call, the operator would have to manually create
            // the row in MC's UI before MC could answer for MM.
            //
            // Both calls are idempotent on the MC side — subscribeToManagerCore
            // uses updateOrInsert, registerPreference is registerDefault
            // which respects admin_overridden=true so it never trampling
            // operator edits.
            if (($data['price_provider'] ?? '') === 'manager-core') {
                try {
                    $priceProviderService = app(\MiningManager\Services\Pricing\PriceProviderService::class);
                    $market = 'jita'; // bootstrap default; MC's preference takes over after
                    $count = $priceProviderService->subscribeToManagerCore($market);
                    Log::info("Mining Manager: Auto-subscribed {$count} type IDs to Manager Core");

                    // Seed MM's pricing preference in MC (idempotent — no-op if
                    // admin has overridden it). market + price_type both reach
                    // MC via the bridge so MC owns the source of truth.
                    try {
                        $bridge = app(\ManagerCore\Services\PluginBridge::class);
                        $bridge->call(
                            'ManagerCore',
                            'pricing.registerPreference',
                            'mining-manager',
                            $market,
                            $data['price_type'] ?? 'sell',
                            'Mining Manager — tax + payout calculations'
                        );
                    } catch (\Throwable $prefEx) {
                        Log::warning('Mining Manager: Failed to seed MC pricing preference: ' . $prefEx->getMessage());
                    }
                } catch (\Exception $subEx) {
                    Log::warning('Mining Manager: Failed to subscribe to Manager Core: ' . $subEx->getMessage());
                }
            }

            // Update other pricing settings via service (these use 'pricing.' prefix).
            //
            // Note: the pricing.manager_core_market + pricing.manager_core_variant
            // writes that used to live here are gone — those settings were
            // dropped from the pricing tab UI in commit 583ea48 and the
            // last remaining reader was switched to MC's getPreferenceForPlugin
            // bridge call in commit d61e9e9. The forward-only migration
            // 000018 cleans up the legacy rows on existing installs.
            $pricing = [
                'price_type' => $data['price_type'],
                'cache_duration' => $data['cache_duration'],
                'fallback_to_jita' => $request->has('fallback_to_jita'),
                // Refining settings
                'refining_efficiency' => $data['refining_efficiency'],
            ];
            if (isset($data['fallback_provider'])) {
                $pricing['fallback_provider'] = $data['fallback_provider'];
            }
            $this->settingsService->updatePricingSettings($pricing);

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
            // Not exists:corporation_infos: a corporation known only from the
            // plugin's own lookups can be picked too.
            'dashboard_leaderboard_corporation_ids.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('error', 'Validation failed. Please check your inputs.');
        }

        try {
            $data = $validator->validated();

            // The dashboard reads one install-wide filter, so it is saved as one.
            $this->settingsService->updateGlobalSetting(
                'dashboard_leaderboard_corporation_filter',
                $data['dashboard_leaderboard_corporation_filter']
            );

            // Store corporation IDs as JSON
            $corpIds = ($data['dashboard_leaderboard_corporation_filter'] === 'specific')
                ? array_values(array_unique(array_map('intval', $data['dashboard_leaderboard_corporation_ids'] ?? [])))
                : [];
            $this->settingsService->updateGlobalSetting(
                'dashboard_leaderboard_corporation_ids',
                json_encode($corpIds),
                'json'
            );

            // Clear all settings caches
            $this->clearSettingsCache();

            // The leaderboards are cached for five minutes. Without this the
            // dashboard keeps showing the old filter until they run out.
            DashboardController::clearDashboardCache();

            return redirect(route('mining-manager.settings.index') . '#dashboard')
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
            'ledger_retention_days' => 'required|integer|min:30|max:3650',
            'theft_overdue_days' => 'required|integer|min:0|max:365',

            // Checkboxes (nullable because unchecked = not sent)
            'enable_tax_tracking' => 'nullable|boolean',
            'enable_ledger_tracking' => 'nullable|boolean',
            'enable_analytics' => 'nullable|boolean',
            'enable_reports' => 'nullable|boolean',
            'enable_events' => 'nullable|boolean',
            'allow_event_creation' => 'nullable|boolean',
            'enable_moon_tracking' => 'nullable|boolean',
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

                // Moon mining
                'enable_moon_tracking' => $request->has('enable_moon_tracking'),
                'theft_overdue_days' => (int) $data['theft_overdue_days'],

                // Permissions & access
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
    public function export(Request $request)
    {
        try {
            // Webhook URLs are credentials, so they travel only when asked for.
            $settings = $this->settingsService->exportSettings(
                $request->boolean('include_webhooks')
            );

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

            $result = $this->settingsService->importSettings($settings);
            $this->clearSettingsCache();

            $message = "Imported {$result['settings']} setting(s)";

            if ($result['webhooks'] > 0) {
                $message .= " and {$result['webhooks']} webhook(s)";
            }

            if ($result['skipped'] > 0) {
                $message .= ", and left out {$result['skipped']} row(s): records only the install it came from keeps, copies nothing reads, or rows it could not read";
            }

            if ($result['legacy']) {
                $message .= '. This file predates per-corporation export, so everything'
                    . ' was applied globally. Re-export from the source install to keep'
                    . ' corporation settings separate.';
            }

            if (!empty($result['periods'])) {
                $byDate = [];
                foreach ($result['periods'] as $change) {
                    $byDate[$change['from']->format('F j')][] = $change['profile'];
                }

                $message .= '. Billing periods from the file start later, so that no part of a month is billed twice or not at all: '
                    . implode('; ', array_map(fn (string $date, array $profiles) => "{$this->profileNames($profiles)} on {$date}", array_keys($byDate), $byDate))
                    . '.';
            }

            return redirect()->route('mining-manager.settings.index')
                ->with('success', $message);
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
     * Display help page.
     *
     * Passes a `$versionStatus` array (from VersionChecker) so the
     * Overview page's Version Status card can show installed vs latest
     * Packagist tag with an "Up to date / Update available / Pre-release
     * / Dev branch / Unknown" pill. Cached 6h on the service side so
     * each Help visit doesn't hit Packagist.
     *
     * @return \Illuminate\View\View
     */
    public function help()
    {
        $versionStatus = app(\MiningManager\Services\VersionChecker::class)->getStatus();

        return view('mining-manager::help.index', compact('versionStatus'));
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
            $search = (string) $search;
            $corporations = $this->getAvailableCorporations()
                ->filter(fn ($corp) => $search === ''
                    || stripos((string) $corp->name, $search) !== false
                    || stripos((string) $corp->ticker, $search) !== false)
                ->take(20)
                ->values();

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
        $validator = Validator::make($request->all(), $this->webhookValidationRules());

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
            // is_enabled honours form input. If the form omits the field
            // (checkbox unchecked), default to true to match the historical
            // behaviour of "new webhooks come up live".
            $data['is_enabled'] = $request->has('is_enabled') ? $request->boolean('is_enabled') : true;
            $data['notify_theft_detected'] = $request->boolean('notify_theft_detected');
            $data['notify_critical_theft'] = $request->boolean('notify_critical_theft');
            $data['notify_active_theft'] = $request->boolean('notify_active_theft');
            $data['notify_incident_resolved'] = $request->boolean('notify_incident_resolved');
            $data['notify_moon_arrival'] = $request->boolean('notify_moon_arrival');
            $data['notify_jackpot_detected'] = $request->boolean('notify_jackpot_detected');
            $data['notify_moon_chunk_unstable'] = $request->boolean('notify_moon_chunk_unstable');
            $data['notify_extraction_started'] = $request->boolean('notify_extraction_started');
            $data['notify_next_extraction_planned'] = $request->boolean('notify_next_extraction_planned');
            $data['notify_schedule_mismatch'] = $request->boolean('notify_schedule_mismatch');
            $data['notify_refinery_gone'] = $request->boolean('notify_refinery_gone');
            $data['notify_extraction_cancelled'] = $request->boolean('notify_extraction_cancelled');
            $data['notify_moon_not_rescheduled'] = $request->boolean('notify_moon_not_rescheduled');
            $data['notify_schedule_needs_filling'] = $request->boolean('notify_schedule_needs_filling');
            $data['notify_tax_outstanding_digest'] = $request->boolean('notify_tax_outstanding_digest');
            $data['notify_price_provider'] = $request->boolean('notify_price_provider');
            $data['notify_moon_scan_missing'] = $request->boolean('notify_moon_scan_missing');
            $data['notify_extraction_at_risk'] = $request->boolean('notify_extraction_at_risk');
            $data['notify_extraction_lost'] = $request->boolean('notify_extraction_lost');
            $data['notify_metenox_cargo_full'] = $request->boolean('notify_metenox_cargo_full');
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

            $validator = Validator::make($request->all(), $this->webhookValidationRules());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            // Convert checkboxes to booleans (boolean() checks actual value, not just key existence)
            // is_enabled: only update when the form actually submits the
            // field. Edits made via the "edit webhook" modal include
            // is_enabled; the standalone toggle endpoint (toggleWebhook)
            // owns flips made from the table row's switch. Skipping the
            // assignment when the field isn't present preserves whatever
            // the toggle last set.
            if ($request->has('is_enabled')) {
                $data['is_enabled'] = $request->boolean('is_enabled');
            }
            $data['notify_theft_detected'] = $request->boolean('notify_theft_detected');
            $data['notify_critical_theft'] = $request->boolean('notify_critical_theft');
            $data['notify_active_theft'] = $request->boolean('notify_active_theft');
            $data['notify_incident_resolved'] = $request->boolean('notify_incident_resolved');
            $data['notify_moon_arrival'] = $request->boolean('notify_moon_arrival');
            $data['notify_jackpot_detected'] = $request->boolean('notify_jackpot_detected');
            $data['notify_moon_chunk_unstable'] = $request->boolean('notify_moon_chunk_unstable');
            $data['notify_extraction_started'] = $request->boolean('notify_extraction_started');
            $data['notify_next_extraction_planned'] = $request->boolean('notify_next_extraction_planned');
            $data['notify_schedule_mismatch'] = $request->boolean('notify_schedule_mismatch');
            $data['notify_refinery_gone'] = $request->boolean('notify_refinery_gone');
            $data['notify_extraction_cancelled'] = $request->boolean('notify_extraction_cancelled');
            $data['notify_moon_not_rescheduled'] = $request->boolean('notify_moon_not_rescheduled');
            $data['notify_schedule_needs_filling'] = $request->boolean('notify_schedule_needs_filling');
            $data['notify_tax_outstanding_digest'] = $request->boolean('notify_tax_outstanding_digest');
            $data['notify_price_provider'] = $request->boolean('notify_price_provider');
            $data['notify_moon_scan_missing'] = $request->boolean('notify_moon_scan_missing');
            $data['notify_extraction_at_risk'] = $request->boolean('notify_extraction_at_risk');
            $data['notify_extraction_lost'] = $request->boolean('notify_extraction_lost');
            $data['notify_metenox_cargo_full'] = $request->boolean('notify_metenox_cargo_full');
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

            $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
            $result = $notificationService->testWebhook($webhook);

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

    /**
     * Validation rules shared by storeWebhook + updateWebhook.
     *
     * Centralised so the two endpoints can't drift — historically the
     * update endpoint missed several newer notify_* fields (moon_chunk_unstable,
     * extraction_at_risk, extraction_lost) which meant operators couldn't
     * edit those toggles after the initial create.
     *
     * Two cross-cutting safety rules applied to specific fields:
     *
     *  - `webhook_url` requires `https://` prefix in addition to URL format.
     *    Discord/Slack webhooks are always HTTPS so this loses no functionality.
     *    Closes a small SSRF surface where a malicious or compromised admin
     *    could register `http://127.0.0.1:port` to probe internal services.
     *
     *  - `notify_extraction_at_risk` and `notify_extraction_lost` are
     *    cross-plugin notifications that require Manager Core's EventBus +
     *    Structure Manager's FuelCalculator at runtime. The UI greys these
     *    out when either plugin is missing, but a direct API POST or stale
     *    form replay can bypass the UI gate. The closure validator below
     *    rejects toggle=true on the backend if either plugin is absent so
     *    the persisted state can't drift from the runtime capability.
     *
     * @return array<string, mixed>
     */
    private function webhookValidationRules(): array
    {
        $crossPluginRule = function ($attribute, $value, $fail) {
            if (!$value) {
                return; // not enabling — no plugin requirement
            }
            $hasMC = class_exists('ManagerCore\\Services\\EventBus');
            $hasSM = class_exists('StructureManager\\Helpers\\FuelCalculator');
            if (!$hasMC || !$hasSM) {
                $missing = [];
                if (!$hasMC) $missing[] = 'Manager Core';
                if (!$hasSM) $missing[] = 'Structure Manager';
                $fail("Cannot enable {$attribute}: requires " . implode(' + ', $missing) . ' to be installed.');
            }
        };

        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:discord,slack,custom',
            // HTTPS-only — Discord/Slack/standard webhooks are always HTTPS.
            // The starts_with rule blocks http://, file://, gopher://, etc.
            'webhook_url' => ['required', 'url', 'starts_with:https://'],
            // is_enabled — explicit boolean validation. Coercing it via
            // $request->boolean() in the controller without going through the
            // validator means a payload of `is_enabled=banana` silently
            // becomes false instead of raising a validation error.
            'is_enabled' => 'nullable|boolean',
            'notify_theft_detected' => 'nullable|boolean',
            'notify_critical_theft' => 'nullable|boolean',
            'notify_active_theft' => 'nullable|boolean',
            'notify_incident_resolved' => 'nullable|boolean',
            'notify_moon_arrival' => 'nullable|boolean',
            'notify_jackpot_detected' => 'nullable|boolean',
            'notify_moon_chunk_unstable' => 'nullable|boolean',
            'notify_extraction_started' => 'nullable|boolean',
            'notify_next_extraction_planned' => 'nullable|boolean',
            'notify_schedule_mismatch' => 'nullable|boolean',
            'notify_refinery_gone' => 'nullable|boolean',
            'notify_extraction_cancelled' => 'nullable|boolean',
            'notify_moon_not_rescheduled' => 'nullable|boolean',
            'notify_schedule_needs_filling' => 'nullable|boolean',
            'notify_tax_outstanding_digest' => 'nullable|boolean',
            'notify_price_provider' => 'nullable|boolean',
            'notify_moon_scan_missing' => 'nullable|boolean',
            'notify_extraction_at_risk' => ['nullable', 'boolean', $crossPluginRule],
            'notify_extraction_lost' => ['nullable', 'boolean', $crossPluginRule],
            'notify_metenox_cargo_full' => 'nullable|boolean',
            'notify_event_created' => 'nullable|boolean',
            'notify_event_started' => 'nullable|boolean',
            'notify_event_completed' => 'nullable|boolean',
            'notify_tax_generated' => 'nullable|boolean',
            'notify_tax_announcement' => 'nullable|boolean',
            'notify_tax_reminder' => 'nullable|boolean',
            'notify_tax_invoice' => 'nullable|boolean',
            'notify_tax_overdue' => 'nullable|boolean',
            'notify_report_generated' => 'nullable|boolean',
            // Discord role IDs are snowflakes — 64-bit integers serialized
            // as strings. They're 17-20 digits in practice (Discord's
            // snowflake epoch + the bit allocation guarantees this range).
            // A plain `string|max:255` would accept an operator pasting a
            // role NAME instead of an ID; the role-ping tag then renders as
            // `<@&MyRole>` in Discord — a literal string that pings nobody.
            // Rejecting non-numeric values up-front surfaces it at save time.
            'discord_role_id' => ['nullable', 'string', 'regex:/^\d{17,20}$/'],
            'discord_username' => 'nullable|string|max:255',
            'slack_channel' => 'nullable|string|max:255',
            'slack_username' => 'nullable|string|max:255',
            'custom_payload_template' => 'nullable|string',
            // custom_headers is a JSON object on the model (cast='array').
            // Validate the shape so a malformed POST doesn't blow up at
            // the json_encode path during dispatch. Each value must be a
            // string (Discord/Slack/HTTP headers are string-typed) — block
            // arbitrary array/object values that would serialize as JSON
            // inside an HTTP header line and break parsers downstream.
            'custom_headers' => 'nullable|array',
            'custom_headers.*' => 'string|max:1024',
            'corporation_id' => 'nullable|integer',
        ];
    }
}
