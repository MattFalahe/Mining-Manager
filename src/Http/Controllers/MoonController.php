<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Moon\MoonExtractionService;
use MiningManager\Services\Moon\MoonValueCalculationService;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use MiningManager\Models\MiningLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MoonController extends Controller
{
    /**
     * Moon extraction service
     *
     * @var MoonExtractionService
     */
    protected $extractionService;

    /**
     * Moon value calculation service
     *
     * @var MoonValueCalculationService
     */
    protected $valueService;

    /**
     * Constructor
     */
    public function __construct(
        MoonExtractionService $extractionService,
        MoonValueCalculationService $valueService
    ) {
        $this->extractionService = $extractionService;
        $this->valueService = $valueService;
    }

    /**
     * Display all moon extractions
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $features = app(\MiningManager\Services\Configuration\SettingsManagerService::class)->getFeatureFlags();
        if (!($features['enable_moon_tracking'] ?? true)) {
            return redirect()->route('mining-manager.dashboard.index')
                ->with('warning', 'This feature is currently disabled. Enable it in Settings > Features.');
        }

        $status = $request->input('status', 'all');
        $corporationId = $request->input('corporation_id');

        // Quick status sync - detect fractures from late notifications, then expire
        app(\MiningManager\Services\Moon\MoonExtractionService::class)->detectAutoFractures();
        MoonExtraction::expiredByTime()->update(['status' => 'expired']);

        // Update ready status for arrived chunks that aren't expired
        $now = Carbon::now();
        $readyCandidates = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->get()
            ->filter(fn($e) => !$e->isExpired())
            ->pluck('id');

        if ($readyCandidates->isNotEmpty()) {
            MoonExtraction::whereIn('id', $readyCandidates)->update(['status' => 'ready']);
        }

        $query = MoonExtraction::with(['structure', 'corporation']);

        if ($status !== 'all') {
            // Map 'completed' filter to actual database statuses
            if ($status === 'completed') {
                $query->whereIn('status', ['expired', 'fractured', 'completed']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        $extractions = $query->orderBy('chunk_arrival_time', 'asc')->paginate(20);

        // Batch-load structure and moon names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($extractions->getCollection());

        // Calculate values for each extraction (respects current refined value setting)
        foreach ($extractions as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->computeDisplayValue($extraction);
            }
        }

        // Get upcoming extractions (next 7 days)
        $upcoming = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', Carbon::now())
            ->where('chunk_arrival_time', '<=', Carbon::now()->addDays(7))
            ->orderBy('chunk_arrival_time')
            ->get();

        // Batch-load display names for upcoming extractions
        MoonExtraction::loadDisplayNames($upcoming);

        // Calculate values for upcoming extractions too
        foreach ($upcoming as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->computeDisplayValue($extraction);
            }
        }

        // Get stats for ALL extractions (not just current page)
        $stats = [
            'extracting' => MoonExtraction::where('status', 'extracting')->count(),
            'ready' => MoonExtraction::where('status', 'ready')->count(),
            // Count expired/fractured extractions this month as "completed"
            'completed' => MoonExtraction::whereIn('status', ['expired', 'fractured', 'completed'])
                ->where('chunk_arrival_time', '>=', Carbon::now()->startOfMonth())
                ->count(),
        ];

        // Get archived history for past extractions display.
        // Scoped to Moon Owner Corporation only — other directors' private
        // moons on the same SeAT install are excluded here and from backfill.
        // No limit: DataTables handles client-side pagination/filtering/sorting.
        // For very large installs this could grow; if it becomes a perf concern,
        // move to server-side DataTables (yajra/datatables) or a DB paginator.
        $historyExtractions = collect();
        if ($status === 'completed' || $status === 'all') {
            $moonOwnerCorpId = app(\MiningManager\Services\Configuration\SettingsManagerService::class)
                ->getTaxProgramCorporationId();

            $historyQuery = MoonExtractionHistory::orderBy('chunk_arrival_time', 'desc');

            if ($moonOwnerCorpId !== null) {
                $historyQuery->where('corporation_id', $moonOwnerCorpId);
            }

            $historyExtractions = $historyQuery->get();

            // Batch-load moon + structure display names. loadDisplayNames() on
            // MoonExtraction is generic — it reads structure_id/moon_id and
            // sets moon_name/structure_name attributes. Works on any collection
            // that has those fields, including MoonExtractionHistory rows.
            if ($historyExtractions->isNotEmpty()) {
                MoonExtraction::loadDisplayNames($historyExtractions);
            }
        }

        return view('mining-manager::moon.index', compact('extractions', 'upcoming', 'status', 'stats', 'historyExtractions'));
    }

    /**
     * Display specific moon extraction
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $extraction = MoonExtraction::with(['structure', 'corporation'])->findOrFail($id);

        // Check for late-arriving fracture notifications (ESI can delay hours)
        $extractionService = app(\MiningManager\Services\Moon\MoonExtractionService::class);
        if ($extractionService->detectFractureForExtraction($extraction)) {
            $extraction->refresh(); // Reload with updated fractured_at
        }

        // Calculate estimated value if ore composition available.
        // For jackpot extractions, apply the ~2.0x multiplier so the
        // operator sees the true post-reprocessing value rather than the
        // ESI-predicted base. Pre-jackpot extractions show the base value
        // directly.
        $estimatedValue = null;
        if ($extraction->ore_composition) {
            $baseValue = $this->valueService->calculateExtractionValue($extraction);
            $estimatedValue = $extraction->is_jackpot
                ? $extraction->calculateValueWithJackpotBonus((float) $baseValue)
                : $baseValue;
        }

        // Calculate time until chunk arrival
        $timeUntilArrival = null;
        $timeUntilDecay = null;

        if ($extraction->chunk_arrival_time && $extraction->chunk_arrival_time > Carbon::now()) {
            $timeUntilArrival = Carbon::now()->diffInHours($extraction->chunk_arrival_time);
        }

        // Use model's expiry time (based on fractured_at when available)
        $expiryTime = $extraction->getExpiryTime();
        if ($expiryTime && $expiryTime > Carbon::now()) {
            $timeUntilDecay = Carbon::now()->diffInHours($expiryTime);
        }

        // Time until unstable phase starts (separate from expiry)
        $timeUntilUnstable = null;
        $unstableStart = $extraction->getUnstableStartTime();
        if ($unstableStart && $unstableStart > Carbon::now()) {
            $timeUntilUnstable = Carbon::now()->diffInHours($unstableStart);
        }

        // Load extraction history for this structure.
        //
        // Past extractions live in TWO tables depending on age:
        //   - moon_extraction_history: permanent archive (moved here by
        //     archive-extractions cron, ~7 days after natural_decay_time)
        //   - moon_extractions: contains both live AND recently-terminal
        //     extractions pending archival
        //
        // We merge both sources so recently-expired/fractured/cancelled
        // extractions appear immediately, without waiting 7 days for the
        // archive cron. The 7-day archive cooldown is kept intentionally
        // to allow late ESI data (fracture notifications can lag hours).
        $archivedHistory = \MiningManager\Models\MoonExtractionHistory::where('structure_id', $extraction->structure_id)
            ->orderBy('chunk_arrival_time', 'desc')
            ->limit(10)
            ->get();

        // Pending-archive: terminal-state rows still in moon_extractions.
        // Exclude the current extraction being viewed, and exclude rows
        // that would duplicate an archived entry (matched on chunk_arrival_time).
        $archivedArrivalTimes = $archivedHistory->pluck('chunk_arrival_time')->toArray();

        $pendingExtractions = MoonExtraction::where('structure_id', $extraction->structure_id)
            ->where('id', '!=', $extraction->id)
            ->whereIn('status', ['expired', 'fractured', 'cancelled'])
            ->whereNotIn('chunk_arrival_time', $archivedArrivalTimes)
            ->orderBy('chunk_arrival_time', 'desc')
            ->limit(10)
            ->get();

        // Shape pending rows to match MoonExtractionHistory fields so the
        // blade template can render both types uniformly.
        foreach ($pendingExtractions as $past) {
            $past->final_status = $past->status;
            $past->final_estimated_value = $past->estimated_value ?: null;

            $minedData = $this->calculateActualMined($past);
            $past->actual_mined_value = $minedData['total_value'] ?: null;
            $past->total_miners = $minedData['total_miners'];
            $past->completion_percentage = $minedData['completion_percentage'];
            $past->is_jackpot = $past->is_jackpot ?? false;
        }

        // Merge, sort by chunk_arrival_time (canonical ordering that works
        // for both tables), cap at 10.
        $history = $archivedHistory
            ->concat($pendingExtractions)
            ->sortByDesc(function ($record) {
                return $record->chunk_arrival_time instanceof \Carbon\Carbon
                    ? $record->chunk_arrival_time->timestamp
                    : Carbon::parse($record->chunk_arrival_time)->timestamp;
            })
            ->take(10)
            ->values();

        // Calculate duration in days for each historical extraction
        foreach ($history as $record) {
            $record->duration_days = Carbon::parse($record->extraction_start_time)
                ->diffInDays(Carbon::parse($record->chunk_arrival_time));
        }

        return view('mining-manager::moon.show', compact(
            'extraction',
            'estimatedValue',
            'timeUntilArrival',
            'timeUntilDecay',
            'timeUntilUnstable',
            'history'
        ));
    }

    /**
     * Display moon extraction calendar
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function calendar(Request $request)
    {
        $month = $request->input('month')
            ? Carbon::parse($request->input('month'))
            : Carbon::now();

        // Quick status sync for this month
        $now = Carbon::now();

        // Detect auto-fractures before updating statuses
        app(\MiningManager\Services\Moon\MoonExtractionService::class)->detectAutoFractures();

        // Mark expired using fractured_at when available, legacy estimate otherwise
        MoonExtraction::expiredByTime()->update(['status' => 'expired']);

        // Get extractions for the month (including expired/past)
        $extractions = MoonExtraction::whereBetween('chunk_arrival_time', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        ])->with(['structure', 'corporation'])
            ->orderBy('chunk_arrival_time')
            ->get();

        // Batch-load display names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($extractions);

        // Calculate values for each extraction
        foreach ($extractions as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->computeDisplayValue($extraction);
            }
        }

        // Also get archived history extractions for past months
        $historyExtractions = MoonExtractionHistory::whereBetween('chunk_arrival_time', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        ])->orderBy('chunk_arrival_time')
            ->get();

        // Group by day (current extractions)
        $calendar = [];
        foreach ($extractions as $extraction) {
            $day = $extraction->chunk_arrival_time->format('Y-m-d');
            if (!isset($calendar[$day])) {
                $calendar[$day] = [];
            }
            $calendar[$day][] = $extraction;
        }

        // Convert history extractions to pseudo MoonExtraction objects for display
        $pseudoExtractions = collect();
        foreach ($historyExtractions as $history) {
            $historyExtraction = new MoonExtraction();
            $historyExtraction->id = $history->id;
            $historyExtraction->structure_id = $history->structure_id;
            $historyExtraction->corporation_id = $history->corporation_id;
            $historyExtraction->moon_id = $history->moon_id;
            $historyExtraction->extraction_start_time = $history->extraction_start_time;
            $historyExtraction->chunk_arrival_time = $history->chunk_arrival_time;
            $historyExtraction->natural_decay_time = $history->natural_decay_time;
            $historyExtraction->status = $history->final_status;
            $historyExtraction->ore_composition = $history->ore_composition;
            $historyExtraction->estimated_value = $history->final_estimated_value;
            $historyExtraction->calculated_value = $history->final_estimated_value;
            $historyExtraction->is_jackpot = $history->is_jackpot;
            $historyExtraction->auto_fractured = $history->auto_fractured ?? false;
            $historyExtraction->fractured_at = $history->fractured_at ?? null;
            $historyExtraction->fractured_by = $history->fractured_by ?? null;
            $historyExtraction->is_archived = true;
            $pseudoExtractions->push($historyExtraction);
        }

        // Batch-load display names for history pseudo-objects
        MoonExtraction::loadDisplayNames($pseudoExtractions);

        // Add history extractions to calendar
        foreach ($pseudoExtractions as $historyExtraction) {
            $day = $historyExtraction->chunk_arrival_time->format('Y-m-d');
            if (!isset($calendar[$day])) {
                $calendar[$day] = [];
            }
            $calendar[$day][] = $historyExtraction;
        }

        return view('mining-manager::moon.calendar', compact('calendar', 'month'));
    }

    /**
     * Compute the operator-facing extraction value with the jackpot
     * multiplier applied when applicable.
     *
     * Equivalent to calling MoonOreValueCalculationService::calculateExtractionValue
     * directly, except wraps the result through MoonExtraction::calculateValueWithJackpotBonus
     * if the extraction is flagged as jackpot. Use this anywhere a controller
     * needs to set `$extraction->calculated_value` for view rendering — every
     * view downstream then displays the jackpot-adjusted value automatically
     * without each one having to remember the multiplier.
     *
     * Pre-jackpot (is_jackpot=false) extractions get the raw base value, same
     * as before. Behavioural change is only on confirmed jackpots.
     *
     * @param MoonExtraction $extraction
     * @return float
     */
    private function computeDisplayValue(MoonExtraction $extraction): float
    {
        $base = (float) $this->valueService->calculateExtractionValue($extraction);
        return $extraction->is_jackpot
            ? $extraction->calculateValueWithJackpotBonus($base)
            : $base;
    }

    /**
     * Calculate actual mined value from mining_ledger for an extraction.
     *
     * @param MoonExtraction $extraction
     * @return array{total_value: float, total_miners: int, completion_percentage: float}
     */
    private function calculateActualMined(MoonExtraction $extraction): array
    {
        $default = ['total_value' => 0, 'total_miners' => 0, 'completion_percentage' => 0];

        try {
            if (!$extraction->chunk_arrival_time || !$extraction->natural_decay_time) {
                return $default;
            }

            // Cancelled extractions never had a chunk to mine. Any ledger
            // activity in the window belongs to a different (typically
            // rescheduled) extraction — don't attribute it to this one.
            if ($extraction->status === 'cancelled') {
                return $default;
            }

            // Query by observer_id (the moon drill's ID == structure_id).
            // This is more precise than solar_system_id + is_moon_ore, since
            // it only counts mining on THIS specific structure.
            //
            // Window: 72 hours from chunk_arrival_time. Covers the full
            // lifecycle: up to 3h pre-fracture + 48h post-fracture mining
            // window (roids despawn ~48h after fracture) + buffer for
            // stragglers. Previously the window was chunk_arrival →
            // natural_decay (only 3h pre-fracture), which missed virtually
            // all actual mining since chunks are mined AFTER fracture.
            $windowEnd = $extraction->chunk_arrival_time->copy()->addHours(72);

            $miningData = MiningLedger::where('observer_id', $extraction->structure_id)
                ->where('date', '>=', $extraction->chunk_arrival_time->toDateString())
                ->where('date', '<=', $windowEnd->toDateString())
                ->get();

            if ($miningData->isEmpty()) {
                return $default;
            }

            $totalValue = $miningData->sum('total_value');
            $totalMiners = $miningData->pluck('character_id')->unique()->count();

            // Completion % compares actual mined against the value AT ARRIVAL
            // (locked in when chunk became ready), not against current running
            // value which may have drifted with market prices. Preserves
            // historical accuracy. Falls back to estimated_value if the
            // arrival snapshot isn't available (old rows, backfilled rows).
            $completionPercentage = 0;
            $baseline = $extraction->estimated_value_pre_arrival
                ?: $extraction->estimated_value
                ?: 0;
            if ($baseline > 0) {
                $completionPercentage = min(100, ($totalValue / $baseline) * 100);
            }

            return [
                'total_value' => $totalValue,
                'total_miners' => $totalMiners,
                'completion_percentage' => round($completionPercentage, 2),
            ];
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Display moon compositions and values
     *
     * @return \Illuminate\View\View
     */
    public function compositions()
    {
        // Get recent extractions with composition data
        $extractions = MoonExtraction::whereNotNull('ore_composition')
            ->with(['structure', 'corporation'])
            ->orderBy('extraction_start_time', 'desc')
            ->limit(50)
            ->get();

        // Batch-load display names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($extractions);

        // Calculate values
        foreach ($extractions as $extraction) {
            $extraction->calculated_value = $this->computeDisplayValue($extraction);
        }

        // Group by moon
        $moonData = [];
        foreach ($extractions as $extraction) {
            if ($extraction->moon_id) {
                if (!isset($moonData[$extraction->moon_id])) {
                    $moonData[$extraction->moon_id] = [
                        'moon_id' => $extraction->moon_id,
                        'moon_name' => $extraction->moon_name, // Use accessor
                        'structure_name' => $extraction->structure_name,
                        'extractions' => [],
                        'average_value' => 0,
                    ];
                }
                $moonData[$extraction->moon_id]['extractions'][] = $extraction;
            }
        }

        // Calculate averages
        foreach ($moonData as $moonId => &$data) {
            $totalValue = 0;
            $count = 0;
            foreach ($data['extractions'] as $extraction) {
                if ($extraction->calculated_value) {
                    $totalValue += $extraction->calculated_value;
                    $count++;
                }
            }
            $data['average_value'] = $count > 0 ? $totalValue / $count : 0;
        }

        return view('mining-manager::moon.compositions', compact('moonData'));
    }

    /**
     * Update moon extraction data
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update($id)
    {
        try {
            $extraction = MoonExtraction::findOrFail($id);
            $this->extractionService->updateExtraction($extraction);

            return redirect()->route('mining-manager.moon.show', $extraction->id)
                ->with('success', 'Extraction data updated successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error updating extraction: ' . $e->getMessage());
        }
    }

    /**
     * Refresh all extraction data
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function refreshAll()
    {
        try {
            $result = $this->extractionService->refreshAllExtractions();

            return redirect()->route('mining-manager.moon.index')
                ->with('success', "Updated {$result['updated']} extractions, created {$result['created']} new extractions");
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error refreshing extractions: ' . $e->getMessage());
        }
    }

    /**
     * Get extraction data via AJAX
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function data($id)
    {
        $extraction = MoonExtraction::with(['structure'])->findOrFail($id);

        $data = [
            'id' => $extraction->id,
            'structure_name' => $extraction->structure->name ?? 'Unknown',
            'status' => $extraction->status,
            'chunk_arrival_time' => $extraction->chunk_arrival_time->toIso8601String(),
            'natural_decay_time' => $extraction->natural_decay_time->toIso8601String(),
            'estimated_value' => $extraction->ore_composition
                ? $this->computeDisplayValue($extraction)
                : null,
            'is_jackpot' => (bool) $extraction->is_jackpot,
            'ore_composition' => $extraction->ore_composition,
        ];

        return response()->json($data);
    }

    /**
     * Display extraction list for specific structure
     *
     * @param int $structureId
     * @return \Illuminate\View\View
     */
    public function extractions($structureId)
    {
        // Reject obviously-bad URLs up front. Pre-fix a non-numeric
        // structureId (typo'd link, paste error) reached the query and
        // silently returned an empty paginated result rendered as a blank
        // page. Now we 404 with a clear server-side signal.
        if (!is_numeric($structureId) || (int) $structureId <= 0) {
            abort(404);
        }

        $structureId = (int) $structureId;

        $extractions = MoonExtraction::where('structure_id', $structureId)
            ->with(['structure'])
            ->orderBy('extraction_start_time', 'desc')
            ->paginate(20);

        // Batch-load display names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($extractions->getCollection());

        // Calculate values for each extraction
        foreach ($extractions as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->computeDisplayValue($extraction);
            }
        }

        // If the page returns zero extractions AND no row exists in
        // `corporation_structures` for this id, we're looking at an
        // unknown / never-tracked structure → 404. Otherwise (no
        // extractions but the structure DOES exist in SeAT, e.g. one
        // with no mining cycle yet) render the page with empty results
        // and the structure name so the operator gets context.
        $structure = $extractions->first()?->structure ?? null;
        if (!$structure && $extractions->isEmpty()) {
            $structureExists = DB::table('corporation_structures')
                ->where('structure_id', $structureId)
                ->exists();
            if (!$structureExists) {
                abort(404);
            }
        }

        return view('mining-manager::moon.extractions', compact('extractions', 'structure'));
    }

    /**
     * Display active moon extractions
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function active(Request $request)
    {
        $corporationId = $request->input('corporation_id');

        // Get active extractions (currently extracting)
        $query = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', Carbon::now())
            ->with(['structure', 'corporation']);

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        $activeExtractions = $query->orderBy('chunk_arrival_time')->get();

        // Batch-load display names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($activeExtractions);

        // Calculate values and times for each extraction
        foreach ($activeExtractions as $extraction) {
            // Calculate estimated value if ore composition available
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->computeDisplayValue($extraction);
            }

            // Calculate time until chunk arrival
            $extraction->hours_until_arrival = Carbon::now()->diffInHours($extraction->chunk_arrival_time, false);
            $extraction->time_until_arrival = Carbon::now()->diff($extraction->chunk_arrival_time)->format('%d days, %h hours, %i minutes');

            // Calculate time until expiry (uses fractured_at when available)
            $expiryTime = $extraction->getExpiryTime();
            if ($expiryTime && $expiryTime->isFuture()) {
                $extraction->hours_until_decay = Carbon::now()->diffInHours($expiryTime, false);
                $extraction->time_until_decay = Carbon::now()->diff($expiryTime)->format('%d days, %h hours, %i minutes');
            } elseif ($extraction->natural_decay_time) {
                $extraction->hours_until_decay = Carbon::now()->diffInHours($extraction->natural_decay_time, false);
                $extraction->time_until_decay = Carbon::now()->diff($extraction->natural_decay_time)->format('%d days, %h hours, %i minutes');
            }
        }

        // Get statistics for the view
        $totalValue = $activeExtractions->sum('calculated_value');

        $arrivingSoon = $activeExtractions->filter(function ($extraction) {
            return $extraction->hours_until_arrival <= 24 && $extraction->hours_until_arrival > 0;
        })->count();

        // Get extractions arriving within 24 hours for the urgent section
        $imminentArrivals = $activeExtractions->filter(function ($extraction) {
            return $extraction->hours_until_arrival <= 24 && $extraction->hours_until_arrival > 0;
        });

        return view('mining-manager::moon.active', compact(
            'activeExtractions',
            'totalValue',
            'arrivingSoon',
            'imminentArrivals'
        ));
    }

    /**
     * Display moon extraction simulator
     *
     * @return \Illuminate\View\View
     */
    public function calculator()
    {
        // Get all scanned moons for the dropdown
        $scannedMoons = $this->extractionService->getScannedMoons();

        // Get recent extractions for comparison
        $recentExtractions = MoonExtraction::whereNotNull('ore_composition')
            ->with(['structure'])
            ->orderBy('extraction_start_time', 'desc')
            ->limit(5)
            ->get();

        foreach ($recentExtractions as $extraction) {
            $extraction->calculated_value = $this->computeDisplayValue($extraction);
        }

        return view('mining-manager::moon.calculator', compact('scannedMoons', 'recentExtractions'));
    }

    /**
     * Simulate extraction for a given moon (AJAX endpoint)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function simulate(Request $request)
    {
        $moonId = $request->input('moon_id');
        $extractionDays = $request->input('extraction_days', 14);

        if (!$moonId) {
            return response()->json(['error' => 'Moon ID is required'], 400);
        }

        // Validate extraction days (6-56 days per EVE mechanics)
        $extractionDays = max(6, min(56, (int) $extractionDays));

        $result = $this->extractionService->simulateExtraction($moonId, $extractionDays);

        if (!$result) {
            return response()->json(['error' => 'Moon not found or not scanned'], 404);
        }

        return response()->json($result);
    }

    /**
     * Report a jackpot extraction (member-accessible)
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function reportJackpot($id)
    {
        try {
            $extraction = MoonExtraction::findOrFail($id);

            // Pre-check: short-circuit on an already-jackpot extraction so we
            // can show the friendlier "already marked" message instead of
            // the "race lost" path below. This is racy on its own, but the
            // atomic claim further down is the actual correctness guard.
            if ($extraction->is_jackpot) {
                return redirect()->route('mining-manager.moon.show', $extraction->id)
                    ->with('info', 'This extraction is already marked as a jackpot.');
            }

            // Only allow reporting for extractions where the chunk has arrived
            if ($extraction->chunk_arrival_time->isFuture()) {
                return redirect()->route('mining-manager.moon.show', $extraction->id)
                    ->with('error', 'Cannot report jackpot — the chunk has not arrived yet.');
            }

            // Get the reporting character
            $characterId = auth()->user()->main_character_id ?? auth()->user()->id;
            $characterName = DB::table('character_infos')
                ->where('character_id', $characterId)
                ->value('name') ?? 'Unknown';

            // ATOMIC CLAIM: flip is_jackpot from false→true via compare-and-swap.
            // Without this, two members hitting "Report Jackpot" within the same
            // request window both pass the check above, both save, both fire
            // sendJackpotDetected — duplicate Discord pings for one real event.
            //
            // The where('is_jackpot', false) clause makes this row-level safe:
            // only the worker that flips the flag from false→true gets back
            // claimed=1; everyone else gets 0 and bails. The other jackpot_*
            // metadata columns (detected_at, reported_by, verified) ride along
            // in the same UPDATE so they're consistent with the flag state.
            //
            // Same shape as the StructureAlertHandler dedup latch from the
            // 2026-04-28 audit pass.
            $claimed = MoonExtraction::where('id', $extraction->id)
                ->where('is_jackpot', false)
                ->update([
                    'is_jackpot' => true,
                    'jackpot_detected_at' => now(),
                    'jackpot_reported_by' => $characterId,
                    // null = not yet verified by mining data; verified by
                    // DetectJackpotsCommand once enough mining is observed.
                    'jackpot_verified' => null,
                ]);

            if ($claimed === 0) {
                // Lost the race — another member's request flipped the flag
                // between our pre-check and the atomic UPDATE. Show the same
                // friendly message as the pre-check path.
                return redirect()->route('mining-manager.moon.show', $extraction->id)
                    ->with('info', 'This extraction is already marked as a jackpot.');
            }

            // Refresh local model so the notification dispatch path sees the
            // updated is_jackpot=true (calculateValueWithJackpotBonus depends
            // on the flag being set on the in-memory model).
            $extraction->refresh();

            // Send webhook notification
            try {
                $structure = DB::table('universe_structures')
                    ->where('structure_id', $extraction->structure_id)
                    ->first();

                $systemName = $structure
                    ? (DB::table('solar_systems')->where('system_id', $structure->solar_system_id)->value('name') ?? 'Unknown')
                    : 'Unknown';

                // Load display names for moon_name
                MoonExtraction::loadDisplayNames(collect([$extraction]));

                $baseUrl = rtrim(config('app.url', ''), '/');

                // Apply ~2.0x jackpot multiplier so the notification reflects
                // the post-reprocessing value rather than the ESI-predicted base.
                // is_jackpot was just set to true above so the multiplier kicks in.
                $jackpotValue = (int) round(
                    $extraction->calculateValueWithJackpotBonus((float) ($extraction->estimated_value ?? 0))
                );

                $notificationService = app(\MiningManager\Services\Notification\NotificationService::class);
                $notificationService->sendJackpotDetected([
                    'moon_name' => $extraction->moon_name ?? 'Unknown Moon',
                    'structure_name' => $structure->name ?? "Structure {$extraction->structure_id}",
                    'system_name' => $systemName,
                    'detected_by' => $characterName,
                    'reported_by' => $characterName,
                    'estimated_value' => $jackpotValue,
                    'ore_summary' => $extraction->buildOreSummary(),
                    'extraction_id' => $extraction->id,
                    'extraction_url' => $baseUrl . '/mining-manager/moon/' . $extraction->id,
                ]);
            } catch (\Exception $e) {
                \Log::warning("Mining Manager: Failed to send jackpot notification: {$e->getMessage()}");
            }

            return redirect()->route('mining-manager.moon.show', $extraction->id)
                ->with('success', "Jackpot reported for {$extraction->moon_name}! It will be verified automatically when mining data arrives.");

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error reporting jackpot: ' . $e->getMessage());
        }
    }
}
