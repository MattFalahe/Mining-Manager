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

        // Get upcoming extractions (next 7 days)
        $upcoming = MoonExtraction::where('status', 'extracting')
            ->where('chunk_arrival_time', '>=', Carbon::now())
            ->where('chunk_arrival_time', '<=', Carbon::now()->addDays(7))
            ->orderBy('chunk_arrival_time')
            ->get();

        return view('mining-manager::moons.index', compact('extractions', 'upcoming', 'status'));
    }

    /**
     * Display specific moon extraction
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $extraction = MoonExtraction::with(['structure', 'corporation', 'moon'])->findOrFail($id);

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

        return view('mining-manager::moons.show', compact(
            'extraction',
            'estimatedValue',
            'timeUntilArrival',
            'timeUntilDecay'
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

        // Group by day
        $calendar = [];
        foreach ($extractions as $extraction) {
            $day = $extraction->chunk_arrival_time->format('Y-m-d');
            if (!isset($calendar[$day])) {
                $calendar[$day] = [];
            }
            $calendar[$day][] = $extraction;
        }

        return view('mining-manager::moons.calendar', compact('calendar', 'month'));
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
            ->with(['structure', 'moon'])
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
                        'moon' => $extraction->moon,
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

        return view('mining-manager::moons.compositions', compact('moonData'));
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
        $extraction = MoonExtraction::with(['structure', 'moon'])->findOrFail($id);

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
            ->with(['structure', 'moon'])
            ->orderBy('extraction_start_time', 'desc')
            ->paginate(20);

        $structure = $extractions->first()->structure ?? null;

        return view('mining-manager::moons.extractions', compact('extractions', 'structure'));
    }
}
