<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Services\Moon\MoonExtractionService;
use MiningManager\Services\Moon\MoonValueCalculationService;
use MiningManager\Models\MoonExtraction;
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

        $query = MoonExtraction::with(['structure', 'corporation']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        $extractions = $query->orderBy('chunk_arrival_time', 'desc')->paginate(20);

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
            'completed' => MoonExtraction::where('status', 'completed')
                ->where('chunk_arrival_time', '>=', Carbon::now()->startOfMonth())
                ->count(),
        ];

        return view('mining-manager::moon.index', compact('extractions', 'upcoming', 'status', 'stats'));
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
        $history = \MiningManager\Models\MoonExtractionHistory::where('structure_id', $extraction->structure_id)
            ->orderBy('archived_at', 'desc')
            ->limit(10)
            ->get();

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

        // Get extractions for the month
        $extractions = MoonExtraction::whereBetween('chunk_arrival_time', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()
        ])->with(['structure', 'corporation'])
            ->orderBy('chunk_arrival_time')
            ->get();

        // Calculate values for each extraction
        foreach ($extractions as $extraction) {
            if ($extraction->ore_composition) {
                $extraction->calculated_value = $this->valueService->calculateExtractionValue($extraction);
            }
        }

        // Group by day
        $calendar = [];
        foreach ($extractions as $extraction) {
            $day = $extraction->chunk_arrival_time->format('Y-m-d');
            if (!isset($calendar[$day])) {
                $calendar[$day] = [];
            }
            $calendar[$day][] = $extraction;
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
            ->with(['structure'])
            ->orderBy('extraction_start_time', 'desc')
            ->paginate(20);

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
            ->with(['structure', 'corporation']);

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        $activeExtractions = $query->orderBy('chunk_arrival_time')->get();

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
