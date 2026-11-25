<?php

namespace MiningManager\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Analytics\ChartFilter;
use MiningManager\Services\Analytics\MiningAnalyticsService;
use MiningManager\Services\Character\CharacterNames;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\ClassificationEpoch;
use Seat\Web\Http\Controllers\Controller;

/**
 * Analytics' Performance Charts for the viewer's own mining.
 *
 * Analytics answers for everyone and is for directors. This page asks the same
 * service the same questions, always narrowed to the characters on the
 * viewer's own account, so a member sees their mining and nobody else's.
 */
class MyPerformanceController extends Controller
{
    protected MiningAnalyticsService $analyticsService;

    protected SettingsManagerService $settingsService;

    public function __construct(MiningAnalyticsService $analyticsService, SettingsManagerService $settingsService)
    {
        $this->analyticsService = $analyticsService;
        $this->settingsService = $settingsService;
    }

    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'corporation_id' => 'nullable|integer',
            'character_id' => 'nullable|integer',
            'moon_source' => 'nullable|in:' . implode(',', ChartFilter::MOON_SOURCES),
            'ore_category' => 'nullable|in:' . implode(',', array_keys(ChartFilter::ORE_CATEGORIES)),
        ]);

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        $ownCharacterIds = auth()->user()->characters
            ->pluck('character_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $names = app(CharacterNames::class)->many($ownCharacterIds);
        $characters = collect($ownCharacterIds)
            ->mapWithKeys(fn ($id) => [$id => $names[$id]['name'] ?? "Character {$id}"])
            ->sort();

        // Only corporations the viewer has a character in today, the same
        // affiliation Analytics' corporation filter goes by.
        $corporations = empty($ownCharacterIds)
            ? collect()
            : DB::table('character_affiliations')
                ->join('corporation_infos', 'character_affiliations.corporation_id', '=', 'corporation_infos.corporation_id')
                ->whereIn('character_affiliations.character_id', $ownCharacterIds)
                ->select('character_affiliations.corporation_id', 'corporation_infos.name')
                ->distinct()
                ->orderBy('corporation_infos.name')
                ->pluck('corporation_infos.name', 'character_affiliations.corporation_id');

        // Anything else in the query string is somebody else's, so it is
        // dropped rather than shown.
        $characterId = $request->filled('character_id') ? (int) $request->input('character_id') : null;
        if ($characterId !== null && !in_array($characterId, $ownCharacterIds, true)) {
            $characterId = null;
        }

        $corporationId = $request->filled('corporation_id') ? (int) $request->input('corporation_id') : null;
        if ($corporationId !== null && !$corporations->has($corporationId)) {
            $corporationId = null;
        }

        $moonOwnerCorporationId = (int) $this->settingsService->getSetting('general.moon_owner_corporation_id') ?: null;

        $filter = new ChartFilter(
            (string) $request->input('moon_source', ChartFilter::MOON_ALL),
            $request->input('ore_category'),
            $characterId !== null ? [$characterId] : $ownCharacterIds,
            $moonOwnerCorporationId
        );

        $chartData = [];

        // A filter with no characters means everyone, so an account with no
        // characters asks nothing at all.
        if (!empty($ownCharacterIds)) {
            try {
                $characterActivity = $this->analyticsService->getTopMiners($startDate, $endDate, 50, $corporationId, $filter);

                $chartData = [
                    'mining_trends' => $this->analyticsService->getMiningTrendData($startDate, $endDate, $corporationId, $filter),
                    'ore_distribution' => $this->analyticsService->getOreDistributionData($startDate, $endDate, $corporationId, $filter),
                    'character_activity' => [
                        'labels' => $characterActivity->pluck('name')->toArray(),
                        'data' => $characterActivity->pluck('total_quantity')->toArray(),
                        'values' => $characterActivity->pluck('total_value')->toArray(),
                    ],
                    'system_activity' => $this->analyticsService->getSystemActivityData($startDate, $endDate, $corporationId, $filter),
                    'heatmap' => $this->analyticsService->getHeatmapData($startDate, $endDate, $corporationId, $filter),
                ];
            } catch (\Exception $e) {
                Log::error('Mining Manager: My Performance error: ' . $e->getMessage());

                return back()->with('error', 'An error occurred loading your performance charts.');
            }
        }

        $classificationCutover = $filter->dependsOnClassification()
            ? ClassificationEpoch::get()
            : null;

        return view('mining-manager::ledger.my-performance', [
            'chartData' => $chartData,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'corporations' => $corporations,
            'corporationId' => $corporationId,
            'characters' => $characters,
            'characterId' => $characterId,
            'filter' => $filter,
            'hasCharacters' => !empty($ownCharacterIds),
            'classificationCutover' => $classificationCutover,
            'features' => $this->settingsService->getFeatureFlags(),
        ]);
    }
}
