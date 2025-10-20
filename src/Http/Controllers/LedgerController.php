<?php

namespace MiningManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Web\Http\Controllers\Controller;
use MiningManager\Models\MiningLedger;
use MiningManager\Models\MiningPriceCache;
use MiningManager\Models\MiningTax;
use MiningManager\Models\Setting;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Character\CharacterMiningLedger; // SeAT's DB table, not direct ESI
use Seat\Eveapi\Models\Universe\UniverseType;
use Carbon\Carbon;

class LedgerController extends Controller
{
    /**
     * Display the mining ledger index with all mining activity
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get filter parameters
        $characterId = $request->get('character_id');
        $startDate = $request->get('start_date', now()->subMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $oreType = $request->get('ore_type');
        
        // Build query
        $query = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereBetween('date', [$startDate, $endDate]);

        // Apply filters
        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        if ($oreType) {
            $query->where('type_id', $oreType);
        }

        // Order by date descending
        $query->orderBy('date', 'desc');

        // Paginate results
        $ledgerEntries = $query->paginate(50);

        // Calculate summary statistics
        $stats = $this->calculateLedgerStats($startDate, $endDate, $characterId);

        // Get available characters for filter dropdown
        $characters = CharacterInfo::whereIn('character_id', function($query) {
            $query->select('character_id')
                ->from('mining_ledgers')
                ->distinct();
        })->get();

        // Get ore types for filter dropdown
        $oreTypes = UniverseType::whereIn('type_id', function($query) {
            $query->select('type_id')
                ->from('mining_ledgers')
                ->distinct();
        })->get();

        return view('mining-manager::ledger.index', compact(
            'ledgerEntries',
            'stats',
            'characters',
            'oreTypes',
            'startDate',
            'endDate',
            'characterId',
            'oreType'
        ));
    }

    /**
     * Display personal mining activity for the logged-in user
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function myMining(Request $request)
    {
        // Get user's characters
        $userCharacters = auth()->user()->characters->pluck('character_id');

        if ($userCharacters->isEmpty()) {
            return view('mining-manager::ledger.my-mining', [
                'ledgerEntries' => collect(),
                'stats' => $this->getEmptyStats(),
                'chartData' => $this->getEmptyChartData(),
                'message' => trans('mining-manager::ledger.no_characters'),
            ]);
        }

        // Get date range
        $startDate = $request->get('start_date', now()->subMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');

        // Build query for user's characters
        $query = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$startDate, $endDate]);

        // Filter by specific character if selected
        if ($characterId && $userCharacters->contains($characterId)) {
            $query->where('character_id', $characterId);
        }

        $query->orderBy('date', 'desc');

        $ledgerEntries = $query->paginate(50);

        // Calculate personal statistics
        $stats = $this->calculatePersonalStats($userCharacters, $startDate, $endDate, $characterId);

        // Get chart data for visualizations
        $chartData = $this->getPersonalChartData($userCharacters, $startDate, $endDate, $characterId);

        // Get user's characters for filter
        $characters = CharacterInfo::whereIn('character_id', $userCharacters)->get();

        return view('mining-manager::ledger.my-mining', compact(
            'ledgerEntries',
            'stats',
            'chartData',
            'characters',
            'startDate',
            'endDate',
            'characterId'
        ));
    }

    /**
     * Show the ledger processing form
     *
     * @return \Illuminate\View\View
     */
    public function process()
    {
        // Get last processing date
        $lastProcessed = MiningLedger::max('created_at');

        // Get count of SeAT entries not yet processed into mining manager
        $pendingCount = CharacterMiningLedger::whereNotIn('character_id', function($query) {
            $query->select('character_id')
                ->from('mining_ledgers')
                ->whereColumn('mining_ledgers.date', 'character_mining_ledgers.date')
                ->whereColumn('mining_ledgers.type_id', 'character_mining_ledgers.type_id');
        })->count();

        // Get available characters with mining data
        $characters = CharacterInfo::whereIn('character_id', function($query) {
            $query->select('character_id')
                ->from('character_mining_ledgers')
                ->distinct();
        })->get();

        // Get processing statistics
        $stats = [
            'total_entries' => MiningLedger::count(),
            'last_processed' => $lastProcessed ? $lastProcessed->diffForHumans() : trans('mining-manager::ledger.never'),
            'pending_count' => $pendingCount,
            'characters_count' => $characters->count(),
        ];

        return view('mining-manager::ledger.process', compact('stats', 'characters'));
    }

    /**
     * Process mining ledger data from SeAT's database
     * 
     * This reads from the character_mining_ledgers table that SeAT populates from ESI,
     * calculates prices and taxes, then stores in the mining_ledgers table.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function processSubmit(Request $request)
    {
        $request->validate([
            'character_id' => 'nullable|exists:character_infos,character_id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'recalculate_prices' => 'boolean',
        ]);

        try {
            $characterId = $request->input('character_id');
            $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : null;
            $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null;
            $recalculatePrices = $request->boolean('recalculate_prices', false);

            // Query SeAT's mining ledger table (populated by SeAT from ESI)
            $query = CharacterMiningLedger::query();

            if ($characterId) {
                $query->where('character_id', $characterId);
            }

            if ($startDate) {
                $query->where('date', '>=', $startDate);
            }

            if ($endDate) {
                $query->where('date', '<=', $endDate);
            }

            $seatEntries = $query->get();

            if ($seatEntries->isEmpty()) {
                return redirect()->back()->with('warning', trans('mining-manager::ledger.no_data_to_process'));
            }

            $processed = 0;
            $updated = 0;
            $skipped = 0;

            DB::beginTransaction();

            foreach ($seatEntries as $seatEntry) {
                // Check if entry already exists
                $existing = MiningLedger::where('character_id', $seatEntry->character_id)
                    ->where('date', $seatEntry->date)
                    ->where('type_id', $seatEntry->type_id)
                    ->where('solar_system_id', $seatEntry->solar_system_id)
                    ->first();

                // Get price for the ore
                $price = $this->getOrePrice($seatEntry->type_id, $seatEntry->date, $recalculatePrices);
                $totalValue = $seatEntry->quantity * $price;

                // Calculate tax
                $taxRate = $this->getTaxRate($seatEntry->character_id, $seatEntry->type_id);
                $taxAmount = $totalValue * ($taxRate / 100);

                if ($existing) {
                    // Update existing entry
                    $existing->update([
                        'quantity' => $seatEntry->quantity,
                        'price_per_unit' => $price,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                    ]);
                    $updated++;
                } else {
                    // Create new entry
                    MiningLedger::create([
                        'character_id' => $seatEntry->character_id,
                        'date' => $seatEntry->date,
                        'solar_system_id' => $seatEntry->solar_system_id,
                        'type_id' => $seatEntry->type_id,
                        'quantity' => $seatEntry->quantity,
                        'price_per_unit' => $price,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                    ]);
                    $processed++;
                }
            }

            DB::commit();

            $message = trans('mining-manager::ledger.processing_complete', [
                'processed' => $processed,
                'updated' => $updated,
            ]);

            return redirect()->route('mining-manager.ledger.index')->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return redirect()->back()->with('error', trans('mining-manager::ledger.processing_error', [
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Calculate ledger statistics for given parameters
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $characterId
     * @return array
     */
    protected function calculateLedgerStats($startDate, $endDate, $characterId = null)
    {
        $query = MiningLedger::whereBetween('date', [$startDate, $endDate]);

        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        return [
            'total_quantity' => $query->sum('quantity'),
            'total_value' => $query->sum('total_value'),
            'total_tax' => $query->sum('tax_amount'),
            'unique_characters' => $query->distinct('character_id')->count('character_id'),
            'unique_ores' => $query->distinct('type_id')->count('type_id'),
            'mining_days' => $query->distinct('date')->count('date'),
            'average_daily_value' => $query->groupBy('date')
                ->selectRaw('AVG(daily_total) as avg')
                ->from(DB::raw('(SELECT date, SUM(total_value) as daily_total FROM mining_ledgers GROUP BY date) as daily_totals'))
                ->value('avg') ?? 0,
        ];
    }

    /**
     * Calculate personal statistics for user's characters
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function calculatePersonalStats($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($specificCharacterId) {
            $query->where('character_id', $specificCharacterId);
        }

        // Calculate tax status
        $totalTaxAmount = $query->sum('tax_amount');
        $paidTaxAmount = MiningTax::whereIn('character_id', $characterIds)
            ->whereBetween('month', [$startDate, $endDate])
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'total_quantity' => $query->sum('quantity'),
            'total_value' => $query->sum('total_value'),
            'total_tax_owed' => $totalTaxAmount,
            'total_tax_paid' => $paidTaxAmount,
            'tax_outstanding' => max(0, $totalTaxAmount - $paidTaxAmount),
            'mining_days' => $query->distinct('date')->count('date'),
            'favorite_ore' => $this->getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId),
        ];
    }

    /**
     * Get chart data for personal mining visualization
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getPersonalChartData($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($specificCharacterId) {
            $query->where('character_id', $specificCharacterId);
        }

        // Daily mining value
        $dailyData = $query
            ->select(DB::raw('DATE(date) as date'), DB::raw('SUM(total_value) as value'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top ores by value
        $topOres = $query
            ->join('universe_types', 'mining_ledgers.type_id', '=', 'universe_types.type_id')
            ->select('universe_types.typeName', DB::raw('SUM(total_value) as value'))
            ->groupBy('universe_types.typeName')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        return [
            'daily' => [
                'labels' => $dailyData->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray(),
                'data' => $dailyData->pluck('value')->toArray(),
            ],
            'topOres' => [
                'labels' => $topOres->pluck('typeName')->toArray(),
                'data' => $topOres->pluck('value')->toArray(),
            ],
        ];
    }

    /**
     * Get the favorite ore (most mined by value) for given parameters
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @param string $startDate
     * @param string $endDate
     * @param int|null $specificCharacterId
     * @return array
     */
    protected function getFavoriteOre($characterIds, $startDate, $endDate, $specificCharacterId = null)
    {
        $query = MiningLedger::whereIn('character_id', $characterIds)
            ->whereBetween('date', [$startDate, $endDate]);

        if ($specificCharacterId) {
            $query->where('character_id', $specificCharacterId);
        }

        $favorite = $query
            ->join('universe_types', 'mining_ledgers.type_id', '=', 'universe_types.type_id')
            ->select('universe_types.typeName', DB::raw('SUM(total_value) as total'))
            ->groupBy('universe_types.typeName', 'mining_ledgers.type_id')
            ->orderByDesc('total')
            ->first();

        return $favorite ? [
            'name' => $favorite->typeName,
            'value' => $favorite->total,
        ] : [
            'name' => trans('mining-manager::ledger.no_data'),
            'value' => 0,
        ];
    }

    /**
     * Get ore price from cache or fetch new price
     *
     * @param int $typeId
     * @param string $date
     * @param bool $forceRefresh
     * @return float
     */
    protected function getOrePrice($typeId, $date, $forceRefresh = false)
    {
        $cacheDate = Carbon::parse($date)->startOfDay();

        if (!$forceRefresh) {
            // Try to get cached price
            $cached = MiningPriceCache::where('type_id', $typeId)
                ->where('date', $cacheDate)
                ->first();

            if ($cached) {
                return $cached->price;
            }
        }

        // Get price source from settings
        $priceSource = Setting::get('price_source', 'evepraisal');

        // Fetch price based on source
        $price = $this->fetchOrePrice($typeId, $priceSource);

        // Cache the price
        MiningPriceCache::updateOrCreate(
            ['type_id' => $typeId, 'date' => $cacheDate],
            ['price' => $price, 'source' => $priceSource]
        );

        return $price;
    }

    /**
     * Fetch ore price from external source
     *
     * @param int $typeId
     * @param string $source
     * @return float
     */
    protected function fetchOrePrice($typeId, $source)
    {
        // This is a placeholder - implement actual price fetching based on your price source
        // You might want to use the PriceProviderService here
        
        try {
            // For now, return a default price
            // In production, this should call your price provider service
            return UniverseType::where('type_id', $typeId)->value('basePrice') ?? 0;
        } catch (\Exception $e) {
            \Log::error('Failed to fetch ore price', [
                'type_id' => $typeId,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }
    }

    /**
     * Get tax rate for character and ore type
     *
     * @param int $characterId
     * @param int $typeId
     * @return float
     */
    protected function getTaxRate($characterId, $typeId)
    {
        // Check if ore is moon ore
        $isMoonOre = $this->isMoonOre($typeId);

        // Get appropriate tax rate from settings
        if ($isMoonOre) {
            return (float) Setting::get('moon_ore_tax_rate', 10);
        } else {
            return (float) Setting::get('regular_ore_tax_rate', 5);
        }
    }

    /**
     * Check if type is moon ore
     *
     * @param int $typeId
     * @return bool
     */
    protected function isMoonOre($typeId)
    {
        // Moon ore group IDs in EVE
        $moonOreGroups = [1884, 1920, 1921, 1922, 1923];

        $ore = UniverseType::where('type_id', $typeId)->first();

        return $ore && in_array($ore->groupID, $moonOreGroups);
    }

    /**
     * Get empty statistics array
     *
     * @return array
     */
    protected function getEmptyStats()
    {
        return [
            'total_quantity' => 0,
            'total_value' => 0,
            'total_tax_owed' => 0,
            'total_tax_paid' => 0,
            'tax_outstanding' => 0,
            'mining_days' => 0,
            'favorite_ore' => [
                'name' => trans('mining-manager::ledger.no_data'),
                'value' => 0,
            ],
        ];
    }

    /**
     * Get empty chart data array
     *
     * @return array
     */
    protected function getEmptyChartData()
    {
        return [
            'daily' => [
                'labels' => [],
                'data' => [],
            ],
            'topOres' => [
                'labels' => [],
                'data' => [],
            ],
        ];
    }
}
