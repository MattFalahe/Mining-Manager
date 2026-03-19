<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Moon\MoonExtractionService;
use MiningManager\Services\Moon\MoonValueCalculationService;
use MiningManager\Models\MoonExtraction;
use MiningManager\Models\MoonExtractionHistory;
use Carbon\Carbon;

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
        $status = $request->input('status', 'all');
        $corporationId = $request->input('corporation_id');

        // Quick status sync - update extractions that have expired
        $now = Carbon::now();
        MoonExtraction::where('status', '!=', 'expired')
            ->where('status', '!=', 'fractured')
            ->where('natural_decay_time', '<', $now)
            ->update(['status' => 'expired']);

        // Update ready status for arrived chunks
        MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '<=', $now)
            ->where('natural_decay_time', '>', $now)
            ->update(['status' => 'ready']);

        $query = MoonExtraction::with(['structure.system', 'structure.type', 'corporation']);

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
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
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
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
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

        // Get archived history for past extractions display
        $historyExtractions = collect();
        if ($status === 'completed' || $status === 'all') {
            $historyExtractions = MoonExtractionHistory::orderBy('chunk_arrival_time', 'desc')
                ->limit(20)
                ->get();
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
        $extraction = MoonExtraction::with(['structure.system', 'structure.type', 'corporation'])->findOrFail($id);

        // Calculate estimated value if ore composition available
        $estimatedValue = null;
        if ($extraction->ore_composition) {
            $estimatedValue = $this->valueService->calculateExtractionValue($extraction);
        }

        // Calculate time until chunk arrival
        $timeUntilArrival = null;
        $timeUntilDecay = null;

        if ($extraction->chunk_arrival_time > Carbon::now()) {
            $timeUntilArrival = Carbon::now()->diffInHours($extraction->chunk_arrival_time);
        }

        if ($extraction->natural_decay_time > Carbon::now()) {
            $timeUntilDecay = Carbon::now()->diffInHours($extraction->natural_decay_time);
        }

        // Load extraction history for this structure
        // First check archived history
        $history = \MiningManager\Models\MoonExtractionHistory::where('structure_id', $extraction->structure_id)
            ->orderBy('archived_at', 'desc')
            ->limit(10)
            ->get();

        // If no archived history, show past extractions from the main table
        if ($history->isEmpty()) {
            $pastExtractions = MoonExtraction::where('structure_id', $extraction->structure_id)
                ->where('id', '!=', $extraction->id)
                ->orderBy('chunk_arrival_time', 'desc')
                ->limit(10)
                ->get();

            // Map MoonExtraction fields to match MoonExtractionHistory fields for the view
            foreach ($pastExtractions as $past) {
                $past->final_status = $past->status;
                $past->final_estimated_value = $past->calculated_value ?? $past->estimated_value ?? null;
                $past->actual_mined_value = null;
                $past->completion_percentage = 0;
                $past->total_miners = 0;
                $past->is_jackpot = $past->is_jackpot ?? false;
            }
            $history = $pastExtractions;
        }

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

        // Mark expired based on calculated expiry:
        // Non-autofractured: chunk_arrival + 50h (48h ready + 2h unstable)
        // Autofractured: chunk_arrival + 53h (51h ready + 2h unstable)
        MoonExtraction::where('status', '!=', 'expired')
            ->where('status', '!=', 'fractured')
            ->where(function ($q) use ($now) {
                $q->where(function ($q2) use ($now) {
                    $q2->where('auto_fractured', false)
                       ->where('chunk_arrival_time', '<', $now->copy()->subHours(50));
                })->orWhere(function ($q2) use ($now) {
                    $q2->where('auto_fractured', true)
                       ->where('chunk_arrival_time', '<', $now->copy()->subHours(53));
                });
            })
            ->update(['status' => 'expired']);

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
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
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
            $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
        }

        // Group by moon
        $moonData = [];
        foreach ($extractions as $extraction) {
            if ($extraction->moon_id) {
                if (!isset($moonData[$extraction->moon_id])) {
                    $moonData[$extraction->moon_id] = [
                        'moon_id' => $extraction->moon_id,
                        'moon_name' => $extraction->moon_name, // Use accessor
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
                ? $this->valueService->calculateExtractionValue($extraction)
                : null,
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
        $extractions = MoonExtraction::where('structure_id', $structureId)
            ->with(['structure.system', 'structure.type'])
            ->orderBy('extraction_start_time', 'desc')
            ->paginate(20);

        // Batch-load display names to prevent N+1 queries
        MoonExtraction::loadDisplayNames($extractions->getCollection());

        // Calculate values for each extraction
        foreach ($extractions as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
            }
        }

        $structure = $extractions->first()->structure ?? null;

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
            ->with(['structure.system', 'structure.type', 'corporation']);

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
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
            }

            // Calculate time until chunk arrival
            $extraction->hours_until_arrival = Carbon::now()->diffInHours($extraction->chunk_arrival_time, false);
            $extraction->time_until_arrival = Carbon::now()->diff($extraction->chunk_arrival_time)->format('%d days, %h hours, %i minutes');

            // Calculate time until natural decay
            if ($extraction->natural_decay_time) {
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
            $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
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
}
