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
use Seat\Eveapi\Models\Industry\CharacterMining;
use MiningManager\Services\Character\CharacterInfoService;
use MiningManager\Services\Ledger\LedgerSummaryService;
use MiningManager\Http\Controllers\Traits\EnrichesCharacterData;
use Carbon\Carbon;

class LedgerController extends Controller
{
    use EnrichesCharacterData;

    protected $characterInfoService;
    protected $summaryService;

    public function __construct(CharacterInfoService $characterInfoService, LedgerSummaryService $summaryService)
    {
        $this->characterInfoService = $characterInfoService;
        $this->summaryService = $summaryService;
    }
    /**
     * Display the mining ledger index with all mining activity
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get filter parameters
        $dateFrom = $request->get('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');
        $corporationId = $request->get('corporation_id');
        $oreType = $request->get('ore_type');
        $system = $request->get('system');
        $sortBy = $request->get('sort_by', 'date_desc');
        $perPage = $request->get('per_page', 50);

        // Build query with eager loading
        $query = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereBetween('date', [$dateFrom, $dateTo]);

        // Apply filters
        if ($characterId) {
            $query->where('character_id', $characterId);
        }

        // Corporation filter
        if ($corporationId) {
            $query->whereHas('character', function($q) use ($corporationId) {
                $q->whereIn('character_id', function($subQuery) use ($corporationId) {
                    $subQuery->select('character_id')
                        ->from('character_affiliations')
                        ->where('corporation_id', $corporationId);
                });
            });
        }

        if ($oreType) {
            switch ($oreType) {
                case 'ore':
                    $query->where('is_moon_ore', false)
                          ->where('is_ice', false)
                          ->where('is_gas', false);
                    break;
                case 'moon':
                    $query->where('is_moon_ore', true);
                    break;
                case 'ice':
                    $query->where('is_ice', true);
                    break;
                case 'gas':
                    $query->where('is_gas', true);
                    break;
            }
        }
        
        if ($system) {
            $query->where('solar_system_name', 'like', '%' . $system . '%');
        }

        // Apply sorting
        switch ($sortBy) {
            case 'date_desc':
                $query->orderBy('date', 'desc');
                break;
            case 'date_asc':
                $query->orderBy('date', 'asc');
                break;
            case 'value_desc':
                $query->orderBy('total_value', 'desc');
                break;
            case 'value_asc':
                $query->orderBy('total_value', 'asc');
                break;
            case 'quantity_desc':
                $query->orderBy('quantity', 'desc');
                break;
            default:
                $query->orderBy('date', 'desc');
        }

        // Paginate results
        $ledgerEntries = $query->paginate($perPage);
        
        // Enrich with character information (names, corporations for external characters)
        $ledgerEntries = $this->enrichPaginatorWithCharacterInfo(
            $ledgerEntries,
            $this->characterInfoService
        );

        // Calculate summary statistics for current month
        $topOre = MiningLedger::whereBetween('date', [$dateFrom, $dateTo])
            ->select('type_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('type_id')
            ->orderBy('total', 'desc')
            ->with('type')
            ->first();
        
        $summary = [
            'total_entries' => MiningLedger::whereBetween('date', [$dateFrom, $dateTo])->count(),
            'total_value' => MiningLedger::whereBetween('date', [$dateFrom, $dateTo])->sum('total_value'),
            'active_miners' => MiningLedger::whereBetween('date', [$dateFrom, $dateTo])
                ->distinct('character_id')->count('character_id'),
            'top_ore_type' => $topOre ? ($topOre->type->typeName ?? 'N/A') : 'N/A',
        ];

        // Get available characters for filter dropdown - include ALL characters (registered + unregistered)
        $characterIds = MiningLedger::select('character_id')
            ->distinct()
            ->pluck('character_id')
            ->toArray();
            
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($characterIds);
        $characters = collect($charactersInfo)->sortBy('name');

        // Get corporations with mining activity
        $corporationIds = DB::table('character_affiliations')
            ->whereIn('character_id', function($query) {
                $query->select('character_id')
                    ->from('mining_ledger')
                    ->distinct();
            })
            ->distinct()
            ->pluck('corporation_id');

        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', $corporationIds)
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::ledger.index', compact(
            'ledgerEntries',
            'summary',
            'characters',
            'corporations',
            'dateFrom',
            'dateTo',
            'characterId',
            'corporationId',
            'oreType',
            'system',
            'sortBy',
            'perPage'
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
        $dateFrom = $request->get('date_from', now()->subMonth()->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));
        $characterId = $request->get('character_id');

        // Build query for user's characters with eager loading
        $query = MiningLedger::with(['character', 'solarSystem', 'type'])
            ->whereIn('character_id', $userCharacters)
            ->whereBetween('date', [$dateFrom, $dateTo]);

        // Filter by specific character if selected
        if ($characterId && $userCharacters->contains($characterId)) {
            $query->where('character_id', $characterId);
        }

        $query->orderBy('date', 'desc');

        $ledgerEntries = $query->paginate(50);
        
        // Enrich with character information
        $ledgerEntries = $this->enrichPaginatorWithCharacterInfo(
            $ledgerEntries,
            $this->characterInfoService
        );

        // Calculate personal statistics
        $stats = $this->calculatePersonalStats($userCharacters, $dateFrom, $dateTo, $characterId);

        // Get chart data for visualizations
        $chartData = $this->getPersonalChartData($userCharacters, $dateFrom, $dateTo, $characterId);

        // Get user's characters for filter - enrich with info
        $charactersInfo = $this->characterInfoService->getBatchCharacterInfo($userCharacters->toArray());
        $characters = collect($charactersInfo)->sortBy('name');

        return view('mining-manager::ledger.my-mining', compact(
            'ledgerEntries',
            'stats',
            'chartData',
            'characters',
            'dateFrom',
            'dateTo',
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
        
        // Convert to Carbon instance if we have a date
        if ($lastProcessed) {
            $lastProcessed = Carbon::parse($lastProcessed);
        }

        // Get last ESI sync time from character_minings table
        $lastSync = CharacterMining::max('updated_at');
        
        // Convert to Carbon instance if we have a date
        if ($lastSync) {
            $lastSync = Carbon::parse($lastSync);
        }

        // Get count of SeAT entries not yet processed into mining manager
        $pendingCount = CharacterMining::whereNotIn('character_id', function($query) {
            $query->select('character_id')
                ->from('mining_ledger')
                ->whereColumn('mining_ledger.date', 'character_minings.date')
                ->whereColumn('mining_ledger.type_id', 'character_minings.type_id');
        })->count();

        // Get available characters with mining data
        $characters = CharacterInfo::whereIn('character_id', function($query) {
            $query->select('character_id')
                ->from('character_minings')
                ->distinct();
        })->get();

        // Get processing statistics
        $stats = [
            'total_entries' => MiningLedger::count(),
            'last_processed' => $lastProcessed ? $lastProcessed->diffForHumans() : trans('mining-manager::ledger.never'),
            'pending_count' => $pendingCount,
            'characters_count' => $characters->count(),
            'pending' => 0,  // Job queue not yet implemented
            'processing' => 0,  // Job queue not yet implemented
        ];

        // TODO: Implement job queue system
        // For now, pass empty queue to prevent view errors
        $queue = [];

        return view('mining-manager::ledger.process', compact('stats', 'characters', 'lastSync', 'queue'));
    }

    /**
     * Process mining ledger data from SeAT's database
     * 
     * This reads from the character_mining_ledgers table that SeAT populates from ESI,
     * calculates prices and taxes, then stores in the mining_ledger table.
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
            $query = CharacterMining::query();

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
                    if ($recalculatePrices) {
                        $existing->update([
                            'quantity' => $seatEntry->quantity,
                            'price' => $price,
                            'total_value' => $totalValue,
                            'tax_rate' => $taxRate,
                            'tax_amount' => $taxAmount,
                        ]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    MiningLedger::create([
                        'character_id' => $seatEntry->character_id,
                        'date' => $seatEntry->date,
                        'type_id' => $seatEntry->type_id,
                        'solar_system_id' => $seatEntry->solar_system_id,
                        'quantity' => $seatEntry->quantity,
                        'price' => $price,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                    ]);
                    $processed++;
                }
            }

            DB::commit();

            return redirect()->back()->with('success', trans('mining-manager::ledger.processed_successfully', [
                'processed' => $processed,
                'updated' => $updated,
                'skipped' => $skipped,
            ]));

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to process mining ledger', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->back()->with('error', trans('mining-manager::ledger.processing_failed', [
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Calculate ledger statistics for a given date range
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

        $stats = [
            'total_quantity' => $query->sum('quantity'),
            'total_value' => $query->sum('total_value'),
            'total_tax' => $query->sum('tax_amount'),
            'unique_miners' => $query->distinct('character_id')->count('character_id'),
            'mining_days' => $query->distinct('date')->count('date'),
        ];

        return $stats;
    }

    /**
     * Calculate personal statistics for given character IDs
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
            ->sum('amount_paid');

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
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as value'))
            ->groupBy('invTypes.typeName')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        return [
            'daily' => [
                'labels' => $dailyData->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray(),
                'data' => $dailyData->pluck('value')->toArray(),
            ],
            'topOres' => [
                'labels' => $topOres->pluck('name')->toArray(),
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
            ->join('invTypes', 'mining_ledger.type_id', '=', 'invTypes.typeID')
            ->select('invTypes.typeName as name', DB::raw('SUM(total_value) as total'))
            ->groupBy('invTypes.typeName', 'mining_ledger.type_id')
            ->orderByDesc('total')
            ->first();

        return $favorite ? [
            'name' => $favorite->name,
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
        $priceSource = Setting::getValue('price_source', 'evepraisal');

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
     * Fetch ore price from external source or SDE
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
            // Query SDE table directly for basePrice
            $basePrice = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('basePrice');
            
            return $basePrice ?? 0;
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
            return (float) Setting::getValue('moon_ore_tax_rate', 10);
        } else {
            return (float) Setting::getValue('regular_ore_tax_rate', 5);
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

        // Query SDE table directly
        $groupId = DB::table('invTypes')
            ->where('typeID', $typeId)
            ->value('groupID');

        return $groupId && in_array($groupId, $moonOreGroups);
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

    /**
     * Get details for a specific mining ledger entry
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function details($id)
    {
        $entry = MiningLedger::with(['character.user', 'solarSystem', 'type'])
            ->findOrFail($id);

        // Get related tax record if exists
        $taxRecord = MiningTax::where('character_id', $entry->character_id)
            ->whereYear('month', Carbon::parse($entry->date)->year)
            ->whereMonth('month', Carbon::parse($entry->date)->month)
            ->first();

        return view('mining-manager::ledger.partials.details', compact('entry', 'taxRecord'));
    }

    /**
     * Delete a specific mining ledger entry
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($id)
    {
        try {
            $entry = MiningLedger::findOrFail($id);
            $entry->delete();

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::ledger.entry_deleted'),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to delete mining ledger entry', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.delete_failed'),
            ], 500);
        }
    }

    /**
     * Bulk delete mining ledger entries
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'entry_ids' => 'required|array',
            'entry_ids.*' => 'integer|exists:mining_ledger,id',
        ]);

        try {
            $deleted = MiningLedger::whereIn('id', $request->input('entry_ids'))->delete();

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::ledger.entries_deleted', ['count' => $deleted]),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to bulk delete mining ledger entries', [
                'ids' => $request->input('entry_ids'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.delete_failed'),
            ], 500);
        }
    }

    /**
     * Export mining ledger data
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $query = MiningLedger::with(['character', 'type', 'solarSystem']);

        // Apply filters if provided
        if ($request->has('character_id') && $request->input('character_id')) {
            $query->where('character_id', $request->input('character_id'));
        }

        if ($request->has('type_id') && $request->input('type_id')) {
            $query->where('type_id', $request->input('type_id'));
        }

        if ($request->has('start_date') && $request->input('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date') && $request->input('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        // If specific entry IDs provided (for selected export)
        if ($request->has('entry_ids')) {
            $entryIds = explode(',', $request->input('entry_ids'));
            $query->whereIn('id', $entryIds);
        }

        $entries = $query->orderBy('date', 'desc')->get();

        $filename = 'mining_ledger_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Date',
                'Character',
                'Ore Type',
                'Solar System',
                'Quantity',
                'Price',
                'Total Value',
                'Tax Rate',
                'Tax Amount',
            ]);

            // CSV Rows
            foreach ($entries as $entry) {
                fputcsv($handle, [
                    Carbon::parse($entry->date)->format('Y-m-d H:i:s'),
                    $entry->character->name ?? 'Unknown',
                    $entry->type->name ?? 'Unknown',
                    $entry->solarSystem->name ?? 'Unknown',
                    number_format($entry->quantity, 2),
                    number_format($entry->price, 2),
                    number_format($entry->total_value, 2),
                    number_format($entry->tax_rate, 2) . '%',
                    number_format($entry->tax_amount, 2),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export personal mining ledger data (only current user's characters)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportPersonal(Request $request)
    {
        // Get current user's character IDs
        $characterIds = auth()->user()->characters->pluck('character_id');

        // Query only for user's characters
        $query = MiningLedger::with(['character', 'type', 'solarSystem'])
            ->whereIn('character_id', $characterIds);

        // Apply date filters if provided
        if ($request->has('start_date') && $request->input('start_date')) {
            $query->where('date', '>=', $request->input('start_date'));
        }

        if ($request->has('end_date') && $request->input('end_date')) {
            $query->where('date', '<=', $request->input('end_date'));
        }

        $entries = $query->orderBy('date', 'desc')->get();

        $filename = 'my_mining_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($handle, [
                'Date',
                'Character',
                'Ore Type',
                'Solar System',
                'Quantity',
                'Price',
                'Total Value',
                'Tax Rate',
                'Tax Amount',
            ]);

            // CSV Rows
            foreach ($entries as $entry) {
                fputcsv($handle, [
                    Carbon::parse($entry->date)->format('Y-m-d H:i:s'),
                    $entry->character->name ?? 'Unknown',
                    $entry->type->name ?? 'Unknown',
                    $entry->solarSystem->name ?? 'Unknown',
                    number_format($entry->quantity, 2),
                    number_format($entry->price, 2),
                    number_format($entry->total_value, 2),
                    number_format($entry->tax_rate, 2) . '%',
                    number_format($entry->tax_amount, 2),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Download a CSV template for manual ledger entry
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadTemplate()
    {
        $filename = 'mining_ledger_template.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // CSV Headers with example row commented
            fputcsv($handle, [
                'Date (YYYY-MM-DD)',
                'Character Name',
                'Ore Type Name',
                'Solar System Name',
                'Quantity',
                'Notes (Optional)',
            ]);

            // Add an example row to help users understand the format
            fputcsv($handle, [
                date('Y-m-d'),
                'Example Character',
                'Veldspar',
                'Jita',
                '10000',
                'Example entry - delete this row',
            ]);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Import mining data from ESI for selected characters
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function importFromESI(Request $request)
    {
        $request->validate([
            'character_ids' => 'required|array',
            'character_ids.*' => 'integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $characterIds = $request->input('character_ids');
        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        try {
            // Queue a job to import mining data from ESI
            // In a production environment, this would dispatch a job
            // For now, we'll process it synchronously
            
            $importedCount = 0;
            
            foreach ($characterIds as $characterId) {
                // Verify character exists and user has access
                $character = CharacterInfo::find($characterId);
                if (!$character) {
                    continue;
                }

                // Get mining ledger from SeAT's character_mining table
                $miningData = CharacterMining::where('character_id', $characterId)
                    ->whereBetween('date', [$dateFrom, $dateTo])
                    ->get();

                foreach ($miningData as $mining) {
                    // Check if entry already exists
                    $exists = MiningLedger::where('character_id', $characterId)
                        ->where('type_id', $mining->type_id)
                        ->where('date', $mining->date)
                        ->where('solar_system_id', $mining->solar_system_id)
                        ->exists();

                    if (!$exists) {
                        // Get price from cache or API
                        $price = $this->getTypePrice($mining->type_id);
                        
                        MiningLedger::create([
                            'character_id' => $characterId,
                            'type_id' => $mining->type_id,
                            'quantity' => $mining->quantity,
                            'date' => $mining->date,
                            'solar_system_id' => $mining->solar_system_id,
                            'price' => $price,
                            'total_value' => $mining->quantity * $price,
                            'tax_rate' => $this->getDefaultTaxRate(),
                            'tax_amount' => ($mining->quantity * $price) * ($this->getDefaultTaxRate() / 100),
                            'processed' => false,
                        ]);
                        
                        $importedCount++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => trans('mining-manager::ledger.import_success', ['count' => $importedCount]),
                'imported_count' => $importedCount,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.import_failed') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload and process CSV file
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadCSV(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('csv_file');
            $csvData = array_map('str_getcsv', file($file->getRealPath()));
            $header = array_shift($csvData); // Remove header row

            $importedCount = 0;
            $errors = [];

            foreach ($csvData as $rowIndex => $row) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Skip example row if it exists
                if (isset($row[1]) && str_contains(strtolower($row[1]), 'example')) {
                    continue;
                }

                try {
                    // Expected format: Date, Character Name, Ore Type Name, Solar System Name, Quantity, Notes
                    $date = $row[0] ?? null;
                    $characterName = $row[1] ?? null;
                    $oreTypeName = $row[2] ?? null;
                    $systemName = $row[3] ?? null;
                    $quantity = $row[4] ?? 0;

                    // Validate required fields
                    if (!$date || !$characterName || !$oreTypeName || !$systemName || !$quantity) {
                        $errors[] = "Row " . ($rowIndex + 2) . ": Missing required fields";
                        continue;
                    }

                    // Find character by name
                    $character = CharacterInfo::where('name', $characterName)->first();
                    if (!$character) {
                        $errors[] = "Row " . ($rowIndex + 2) . ": Character '{$characterName}' not found";
                        continue;
                    }

                    // Find ore type by name using SeAT's Sde\InvType model
                    $oreType = \Seat\Eveapi\Models\Sde\InvType::where('typeName', $oreTypeName)->first();
                    if (!$oreType) {
                        $errors[] = "Row " . ($rowIndex + 2) . ": Ore type '{$oreTypeName}' not found";
                        continue;
                    }

                    // Find solar system by name using SeAT's Sde\SolarSystem model
                    $solarSystem = \Seat\Eveapi\Models\Sde\SolarSystem::where(function($query) use ($systemName) {
                        $query->where('solarSystemName', $systemName)
                              ->orWhere('name', $systemName)
                              ->orWhere('system_name', $systemName);
                    })->first();
                    if (!$solarSystem) {
                        $errors[] = "Row " . ($rowIndex + 2) . ": Solar system '{$systemName}' not found";
                        continue;
                    }

                    // Get price (use model's primary key)
                    $price = $this->getTypePrice($oreType->getKey());
                    $totalValue = $quantity * $price;
                    $taxRate = $this->getDefaultTaxRate();
                    $taxAmount = $totalValue * ($taxRate / 100);

                    // Create ledger entry (use model's getKey() to get primary key values)
                    MiningLedger::create([
                        'character_id' => $character->character_id,
                        'type_id' => $oreType->getKey(),
                        'quantity' => $quantity,
                        'date' => Carbon::parse($date),
                        'solar_system_id' => $solarSystem->getKey(),
                        'price' => $price,
                        'total_value' => $totalValue,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $taxAmount,
                        'processed' => false,
                    ]);

                    $importedCount++;

                } catch (\Exception $e) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                }
            }

            if ($importedCount > 0) {
                return response()->json([
                    'success' => true,
                    'message' => trans('mining-manager::ledger.csv_import_success', ['count' => $importedCount]),
                    'imported_count' => $importedCount,
                    'errors' => $errors,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => trans('mining-manager::ledger.csv_no_valid_rows'),
                    'errors' => $errors,
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.csv_import_failed') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle the processing queue (pause/resume)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleQueue(Request $request)
    {
        try {
            $currentState = Setting::get('ledger_queue_paused', false);
            $newState = !$currentState;
            
            Setting::set('ledger_queue_paused', $newState);

            return response()->json([
                'success' => true,
                'paused' => $newState,
                'message' => $newState 
                    ? trans('mining-manager::ledger.queue_paused')
                    : trans('mining-manager::ledger.queue_resumed'),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.toggle_failed') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper: Get type price from cache or API
     *
     * @param int $typeId
     * @return float
     */
    private function getTypePrice($typeId)
    {
        $cached = MiningPriceCache::where('type_id', $typeId)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return $cached->price;
        }

        // Default fallback price if no cache available
        return 0.0;
    }

    /**
     * Helper: Get default tax rate from settings
     *
     * @return float
     */
    private function getDefaultTaxRate()
    {
        return (float) Setting::get('default_tax_rate', 10.0);
    }

    /**
     * Retry a failed processing job
     *
     * @param int $id Job ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function retryJob($id)
    {
        try {
            // TODO: Implement job queue system with database table
            // For now, return a message that the feature is not yet implemented
            // but the route exists to prevent errors
            
            // When implemented, this should:
            // 1. Find the job by ID in the jobs table
            // 2. Reset its status to 'pending'
            // 3. Clear any error messages
            // 4. Re-queue it for processing
            
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.job_queue_not_implemented'),
            ], 501); // 501 = Not Implemented

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => trans('mining-manager::ledger.retry_failed') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the log for a processing job
     *
     * @param int $id Job ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getJobLog($id)
    {
        try {
            // TODO: Implement job queue system with database table
            // For now, return a message that the feature is not yet implemented
            // but the route exists to prevent errors
            
            // When implemented, this should:
            // 1. Find the job by ID in the jobs table
            // 2. Return the job's log/error message
            // 3. Format it for display in the modal
            
            return response()->json([
                'success' => false,
                'log' => trans('mining-manager::ledger.job_queue_not_implemented'),
            ], 501); // 501 = Not Implemented

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'log' => trans('mining-manager::ledger.log_not_found') . ': ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display hierarchical mining summary with character monthly totals
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function summaryIndex(Request $request)
    {
        // Get month parameter or default to current month
        $month = $request->get('month', now()->format('Y-m'));
        $corporationId = $request->get('corporation_id');
        $groupByMain = $request->get('group_by_main', true); // Default to grouped view

        // Validate month format
        try {
            $monthDate = Carbon::parse($month)->startOfMonth();
        } catch (\Exception $e) {
            $monthDate = now()->startOfMonth();
            $month = $monthDate->format('Y-m');
        }

        // Get enhanced monthly summaries (includes ore types and systems)
        $summaries = $this->summaryService->getEnhancedMonthlySummaries($month, $corporationId);

        // Group by main character if requested
        if ($groupByMain) {
            $summaries = $this->summaryService->groupByMainCharacter($summaries);
        }

        // Enrich with character information (names, corporations for unregistered characters)
        $summaries = $this->enrichWithCharacterInfo($summaries, $this->characterInfoService);

        // Calculate totals
        $totals = [
            'total_value' => $summaries->sum('total_value'),
            'total_tax' => $summaries->sum('total_tax'),
            'total_quantity' => $summaries->sum('total_quantity'),
            'moon_ore_value' => $summaries->sum('moon_ore_value'),
            'regular_ore_value' => $summaries->sum('regular_ore_value'),
            'ice_value' => $summaries->sum('ice_value'),
            'gas_value' => $summaries->sum('gas_value'),
        ];

        // Get corporations for filter dropdown
        $corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', function($query) use ($monthDate) {
                $query->select('corporation_id')
                    ->from('mining_ledger')
                    ->whereYear('date', $monthDate->year)
                    ->whereMonth('date', $monthDate->month)
                    ->whereNotNull('corporation_id')
                    ->distinct();
            })
            ->select('corporation_id', 'name', 'ticker')
            ->orderBy('name')
            ->get();

        return view('mining-manager::ledger.summary', [
            'summaries' => $summaries,
            'totals' => $totals,
            'month' => $month,
            'monthDate' => $monthDate,
            'corporations' => $corporations,
            'selectedCorporationId' => $corporationId,
            'isCurrentMonth' => $monthDate->isSameMonth(now()),
            'groupByMain' => $groupByMain,
        ]);
    }

    /**
     * Get daily breakdown for a specific character and month (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterDailySummary(Request $request, $characterId)
    {
        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Validate month format
            $monthDate = Carbon::parse($month)->startOfMonth();

            // Get daily summaries using the service
            $dailySummaries = $this->summaryService->getDailySummaries($characterId, $month);

            // Format the data for the response with system breakdown
            $formattedData = $dailySummaries->map(function ($summary) use ($characterId, $monthDate) {
                $date = $summary->date instanceof Carbon ? $summary->date : Carbon::parse($summary->date);

                // Get system breakdown for this date
                $systems = MiningLedger::where('character_id', $characterId)
                    ->whereDate('date', $date)
                    ->select('solar_system_id', 'solar_system_name', DB::raw('SUM(total_value) as system_value'), DB::raw('SUM(quantity) as system_quantity'))
                    ->groupBy('solar_system_id', 'solar_system_name')
                    ->orderByDesc('system_value')
                    ->get()
                    ->map(function($sys) {
                        return [
                            'system_id' => $sys->solar_system_id,
                            'system_name' => $sys->solar_system_name,
                            'value' => number_format($sys->system_value, 2),
                            'quantity' => number_format($sys->system_quantity, 2),
                        ];
                    });

                return [
                    'date' => $date->format('Y-m-d'),
                    'total_quantity' => number_format($summary->total_quantity, 2),
                    'total_value' => number_format($summary->total_value, 2),
                    'total_tax' => number_format($summary->total_tax, 2),
                    'moon_ore_value' => number_format($summary->moon_ore_value, 2),
                    'regular_ore_value' => number_format($summary->regular_ore_value, 2),
                    'ice_value' => number_format($summary->ice_value, 2),
                    'gas_value' => number_format($summary->gas_value, 2),
                    'systems' => $systems,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load daily summaries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed mining entries for a specific character and date (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedEntries(Request $request, $characterId)
    {
        $date = $request->get('date');

        try {
            // Validate date format
            $dateCarbon = Carbon::parse($date);

            // Get detailed entries for the day
            $entries = MiningLedger::with(['solarSystem', 'type'])
                ->where('character_id', $characterId)
                ->whereDate('date', $dateCarbon)
                ->orderBy('date', 'desc')
                ->get();

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'system_name' => $entry->solarSystem->name ?? 'Unknown',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load detailed entries: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get character mining details in a specific system (AJAX)
     *
     * @param Request $request
     * @param int $characterId
     * @param int $systemId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCharacterSystemDetails(Request $request, $characterId, $systemId)
    {
        $month = $request->get('month', now()->format('Y-m'));

        try {
            // Get system details using the service
            $entries = $this->summaryService->getCharacterSystemDetails($characterId, $month, $systemId);

            // Format the data for the response
            $formattedData = $entries->map(function ($entry) {
                return [
                    'date' => $entry->date->format('Y-m-d H:i:s'),
                    'quantity' => number_format($entry->quantity, 2),
                    'total_value' => number_format($entry->total_value, 2),
                    'tax_amount' => number_format($entry->tax_amount, 2),
                    'ore_type' => $entry->ore_type,
                    'type_name' => $entry->type->typeName ?? 'Unknown',
                    'type_id' => $entry->type_id,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'system_name' => $entries->first()->solarSystem->name ?? 'Unknown System',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load system details: ' . $e->getMessage(),
            ], 500);
        }
    }
}
