<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningTax;
use MiningManager\Models\MiningEvent;
use MiningManager\Models\MoonExtraction;
use MiningManager\Services\Analytics\MiningAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    /**
     * Analytics service
     *
     * @var MiningAnalyticsService
     */
    protected $analyticsService;

    /**
     * Constructor
     *
     * @param MiningAnalyticsService $analyticsService
     */
    public function __construct(MiningAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get mining ledger data
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ledger(Request $request)
    {
        $characterId = $request->input('character_id');
        $startDate = $request->input('start_date') 
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        $query = MiningLedger::whereBetween('date', [$startDate, $endDate]);

        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        $ledger = $query->orderBy('date', 'desc')
            ->limit($request->input('limit', 100))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $ledger,
            'count' => $ledger->count(),
        ]);
    }

    /**
     * Get tax data
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function taxes(Request $request)
    {
        $characterId = $request->input('character_id');
        $status = $request->input('status');
        $month = $request->input('month');

        $query = MiningTax::with('character');

        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($month) {
            $query->where('month', Carbon::parse($month)->format('Y-m-01'));
        }

        $taxes = $query->orderBy('month', 'desc')
            ->limit($request->input('limit', 100))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $taxes,
            'count' => $taxes->count(),
        ]);
    }

    /**
     * Get mining events
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function events(Request $request)
    {
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = MiningEvent::with(['participants', 'creator']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('start_time', [
                Carbon::parse($startDate),
                Carbon::parse($endDate)
            ]);
        }

        $events = $query->orderBy('start_time', 'desc')
            ->limit($request->input('limit', 50))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $events,
            'count' => $events->count(),
        ]);
    }

    /**
     * Get moon extractions
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function extractions(Request $request)
    {
        $status = $request->input('status');
        $corporationId = $request->input('corporation_id');
        $structureId = $request->input('structure_id');

        $query = MoonExtraction::with(['structure', 'corporation']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($corporationId) {
            $query->where('corporation_id', $corporationId);
        }

        if ($structureId) {
            $query->where('structure_id', $structureId);
        }

        $extractions = $query->orderBy('chunk_arrival_time', 'desc')
            ->limit($request->input('limit', 50))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $extractions,
            'count' => $extractions->count(),
        ]);
    }

    /**
     * Get analytics summary
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function analytics(Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        $analytics = [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'total_volume' => $this->analyticsService->getTotalVolume($startDate, $endDate),
            'total_value' => $this->analyticsService->getTotalValue($startDate, $endDate),
            'unique_miners' => $this->analyticsService->getUniqueMinerCount($startDate, $endDate),
            'top_miners' => $this->analyticsService->getTopMiners($startDate, $endDate, 10),
            'ore_breakdown' => $this->analyticsService->getOreBreakdown($startDate, $endDate),
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    /**
     * Get dashboard metrics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function metrics()
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        $metrics = [
            'today' => [
                'mined' => MiningLedger::whereDate('date', $today)->sum('quantity'),
                'miners' => MiningLedger::whereDate('date', $today)->distinct('character_id')->count(),
            ],
            'this_month' => [
                'mined' => MiningLedger::where('date', '>=', $thisMonth)->sum('quantity'),
                'miners' => MiningLedger::where('date', '>=', $thisMonth)->distinct('character_id')->count(),
            ],
            'taxes' => [
                'unpaid' => MiningTax::where('status', 'unpaid')->sum('amount_owed'),
                'overdue' => MiningTax::where('status', 'overdue')->sum('amount_owed'),
                'paid_this_month' => MiningTax::where('status', 'paid')
                    ->where('paid_at', '>=', $thisMonth)
                    ->sum('amount_paid'),
            ],
            'events' => [
                'active' => MiningEvent::where('status', 'active')->count(),
                'planned' => MiningEvent::where('status', 'planned')->count(),
            ],
            'extractions' => [
                'extracting' => MoonExtraction::where('status', 'extracting')->count(),
                'ready' => MoonExtraction::where('status', 'ready')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $metrics,
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Get character mining summary
     *
     * @param int $characterId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function characterSummary($characterId, Request $request)
    {
        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))
            : Carbon::now()->subDays(30);
        
        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))
            : Carbon::now();

        $miningData = MiningLedger::where('character_id', $characterId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $taxes = MiningTax::where('character_id', $characterId)
            ->get();

        $summary = [
            'character_id' => $characterId,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'mining' => [
                'total_quantity' => $miningData->sum('quantity'),
                'unique_days' => $miningData->pluck('date')->unique()->count(),
                'ore_types' => $miningData->pluck('type_id')->unique()->count(),
            ],
            'taxes' => [
                'total_owed' => $taxes->sum('amount_owed'),
                'total_paid' => $taxes->sum('amount_paid'),
                'unpaid' => $taxes->where('status', 'unpaid')->sum('amount_owed'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Health check endpoint
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('mining-manager.version', '1.0.0'),
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Get characters belonging to a corporation
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function corporationCharacters(Request $request)
    {
        $corporationId = $request->input('corporation_id');

        if (!$corporationId) {
            return response()->json([]);
        }

        $characters = DB::table('character_affiliations')
            ->join('character_infos', 'character_affiliations.character_id', '=', 'character_infos.character_id')
            ->where('character_affiliations.corporation_id', $corporationId)
            ->select('character_infos.character_id', 'character_infos.name')
            ->orderBy('character_infos.name')
            ->get();

        return response()->json($characters);
    }
}
