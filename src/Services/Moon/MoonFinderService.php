<?php

namespace MiningManager\Services\Moon;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\TypeIdRegistry;

/**
 * Find Moons: searches scanned moons by location, composition, value and
 * quality, and rates a moon against the other scanned moons of its class.
 *
 * Read only. Scans come from SeAT's moon reports, locations from SeAT's
 * universe tables and every value from MoonValuation, so a search costs the
 * price provider nothing however many moons it covers.
 */
class MoonFinderService
{
    public const CLASSES = ['R4', 'R8', 'R16', 'R32', 'R64'];

    public const SECURITY_BANDS = ['high', 'low', 'null', 'wormhole'];

    /**
     * The widest "top X% of its class" each rating allows: Exceptional is the
     * top 10% of a class, Excellent the top 25%, Good the top half, Average
     * the top 75%, and the rest are Poor. The rating is read from the same
     * percentage the page shows, so a moon shown as top 11% is never
     * Exceptional.
     */
    public const QUALITY_BANDS = [
        'exceptional' => 10,
        'excellent' => 25,
        'good' => 50,
        'average' => 75,
    ];

    public const QUALITY_ORDER = ['poor', 'average', 'good', 'excellent', 'exceptional'];

    /**
     * Fewer scanned moons of a class than this and a rating says nothing:
     * the only R64 moon on an install would otherwise be Exceptional.
     */
    public const QUALITY_MIN_CLASS_SIZE = 5;

    /**
     * Quality always compares 28-day values, so a moon keeps its rating
     * whatever extraction length someone simulates.
     */
    public const QUALITY_DAYS = 28;

    /**
     * The columns the results table can be sorted on.
     */
    public const SORTS = ['name', 'system', 'constellation', 'region', 'class', 'share', 'value', 'quality'];

    public const PAGE_SIZES = [25, 50, 100];

    public const PLACES_PER_PAGE = 50;

    /**
     * @var MoonValuation
     */
    protected $valuation;

    /**
     * @var array<int, string>|null ore type => R4 to R64
     */
    protected $rarityByType;

    /**
     * @var array<int, array>|null moon id => the refinery of ours on it
     */
    protected $refineryMoons;

    /**
     * @var array<int, array>|null moon id => the live claim on it
     */
    protected $claimedMoons;

    public function __construct(MoonValuation $valuation)
    {
        $this->valuation = $valuation;
    }

    /**
     * Turn request input into criteria the search can trust. Anything not
     * recognised is dropped rather than refused, so an old link still runs.
     */
    public function normalizeCriteria(array $input): array
    {
        $id = function ($value): ?int {
            return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        };
        $number = function ($value): ?float {
            return is_numeric($value) ? (float) $value : null;
        };
        $strings = function ($value): array {
            return array_values(array_filter((array) $value, 'is_string'));
        };

        $rules = [];
        foreach ((array) ($input['rules'] ?? []) as $rule) {
            if (!is_array($rule)
                || !in_array($rule['rarity'] ?? null, self::CLASSES, true)
                || !is_numeric($rule['min'] ?? null)) {
                continue;
            }
            $rules[] = ['rarity' => $rule['rarity'], 'min' => max(0.0, min(100.0, (float) $rule['min']))];
        }

        $ores = [];
        foreach ((array) ($input['ores'] ?? []) as $typeId) {
            if (is_numeric($typeId) && (int) $typeId > 0) {
                $ores[] = (int) $typeId;
            }
        }

        $richness = $number($input['richness_min'] ?? null);
        $quality = $input['quality_min'] ?? null;
        $station = $input['station'] ?? null;
        $claim = $input['claim'] ?? null;
        $sort = $input['sort'] ?? null;
        $perPage = (int) ($input['per_page'] ?? 0);
        $days = is_numeric($input['days'] ?? null) ? (int) $input['days'] : self::QUALITY_DAYS;

        return [
            'moon_id' => null,
            'region_id' => $id($input['region_id'] ?? null),
            'constellation_id' => $id($input['constellation_id'] ?? null),
            'system_id' => $id($input['system_id'] ?? null),
            'security' => array_values(array_intersect(self::SECURITY_BANDS, $strings($input['security'] ?? []))),
            'classes' => array_values(array_intersect(self::CLASSES, $strings($input['classes'] ?? []))),
            'rules' => $rules,
            'richness_min' => $richness === null ? null : max(0.0, min(100.0, $richness)),
            'ores' => array_values(array_unique($ores)),
            'days' => max(6, min(56, $days)),
            'basis' => ($input['basis'] ?? null) === 'refined' ? 'refined' : 'ore',
            'value_min' => $number($input['value_min'] ?? null),
            'value_max' => $number($input['value_max'] ?? null),
            'quality_min' => is_string($quality) && $quality !== 'poor' && in_array($quality, self::QUALITY_ORDER, true) ? $quality : null,
            'station' => in_array($station, ['ours', 'free'], true) ? $station : null,
            'claim' => in_array($claim, ['claimed', 'free'], true) ? $claim : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'value',
            'direction' => ($input['direction'] ?? null) === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array($perPage, self::PAGE_SIZES, true) ? $perPage : self::PAGE_SIZES[0],
        ];
    }

    /**
     * Scanned moons matching the criteria, valued and rated.
     *
     * @param bool $paginate false returns every match, for the CSV export
     */
    public function search(array $criteria, bool $paginate = true): array
    {
        $moons = $this->loadMoons($criteria);
        $this->valuation->prepare($this->oreTypesIn($moons));
        $classValues = $this->classValues();
        $refineries = $this->refineryMoons();
        $claims = $this->claimedMoons();

        $matches = [];
        foreach ($moons as $moon) {
            $station = $refineries[$moon['moon_id']] ?? null;
            if ($criteria['station'] === 'ours' && $station === null) {
                continue;
            }
            if ($criteria['station'] === 'free' && $station !== null) {
                continue;
            }

            $claim = $claims[$moon['moon_id']] ?? null;
            if ($criteria['claim'] === 'claimed' && $claim === null) {
                continue;
            }
            if ($criteria['claim'] === 'free' && $claim !== null) {
                continue;
            }

            $rarityShares = $this->rarityShares($moon['ores']);
            $class = $this->classFrom($rarityShares);

            if (!$this->matchesComposition($moon, $rarityShares, $class, $criteria)) {
                continue;
            }

            $band = self::securityBand($moon['region_id'], $moon['security']);
            if (!empty($criteria['security']) && !in_array($band, $criteria['security'], true)) {
                continue;
            }

            $valued = $this->valuation->value($moon['ores'], $criteria['days']);
            $value = $criteria['basis'] === 'refined' ? $valued['refined'] : $valued['raw'];

            if ($criteria['value_min'] !== null && $value < $criteria['value_min']) {
                continue;
            }
            if ($criteria['value_max'] !== null && $value > $criteria['value_max']) {
                continue;
            }

            // Rated on a 28-day valuation worked out exactly as the class
            // values were. Scaling the searched length instead lets per-ore
            // rounding drop a moon on a band's edge into the band below.
            $value28 = $value;
            if ($criteria['days'] !== self::QUALITY_DAYS) {
                $valued28 = $this->valuation->value($moon['ores'], self::QUALITY_DAYS);
                $value28 = $criteria['basis'] === 'refined' ? $valued28['refined'] : $valued28['raw'];
            }

            $quality = $this->quality($class, $value28, $criteria['basis'], $classValues);
            if ($criteria['quality_min'] !== null && !$this->qualityAtLeast($quality, $criteria['quality_min'])) {
                continue;
            }

            $matches[] = $this->row($moon, $valued, $class, $rarityShares, $band, $quality, $value, [
                'station' => $station,
                'claim' => $claim,
            ]);
        }

        $this->sortRows($matches, $criteria['sort'], $criteria['direction']);

        $matched = count($matches);
        $perPage = $criteria['per_page'];
        $pages = max(1, (int) ceil($matched / $perPage));
        $page = min($criteria['page'], $pages);

        return [
            'total_scanned' => $this->scannedMoonCount(),
            'matched' => $matched,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'days' => $criteria['days'],
            'basis' => $criteria['basis'],
            'sort' => $criteria['sort'],
            'direction' => $criteria['direction'],
            'rows' => $paginate ? array_slice($matches, ($page - 1) * $perPage, $perPage) : $matches,
        ];
    }

    /**
     * Class and quality of one scanned moon, or null when it has no scan.
     */
    public function assess(int $moonId, string $basis): ?array
    {
        $moon = $this->loadMoons(['moon_id' => $moonId])[$moonId] ?? null;
        if ($moon === null) {
            return null;
        }

        $class = $this->classFrom($this->rarityShares($moon['ores']));
        $valued = $this->valuation->value($moon['ores'], self::QUALITY_DAYS);
        $value = $basis === 'refined' ? $valued['refined'] : $valued['raw'];

        return [
            'class' => $class,
            'quality' => $this->quality($class, $value, $basis),
            'station' => $this->refineryMoons()[$moonId] ?? null,
            'claim' => $this->claimedMoons()[$moonId] ?? null,
        ];
    }

    /**
     * Up to three scanned moons of the same class that are worth more than
     * this one over the same extraction length.
     *
     * The search looks where the lookup looked: a constellation, else a
     * region. Without a lookup it tries the moon's own constellation, then its
     * region when nothing there is better.
     */
    public function betterMoons(int $moonId, int $days, string $basis, ?int $constellationId = null, ?int $regionId = null): array
    {
        $target = $this->loadMoons(['moon_id' => $moonId])[$moonId] ?? null;
        if ($target === null) {
            return ['class' => null, 'scope' => null, 'days' => $days, 'moons' => []];
        }

        $class = $this->classFrom($this->rarityShares($target['ores']));
        $valued = $this->valuation->value($target['ores'], $days);
        $targetValue = $basis === 'refined' ? $valued['refined'] : $valued['raw'];

        if ($constellationId !== null) {
            $scopes = [['constellation_id' => $constellationId]];
        } elseif ($regionId !== null) {
            $scopes = [['region_id' => $regionId]];
        } else {
            $scopes = [
                ['constellation_id' => $target['constellation_id']],
                ['region_id' => $target['region_id']],
            ];
        }

        $scopeInfo = null;
        foreach ($scopes as $scope) {
            $scopeInfo = $this->describeScope($scope, $target);
            if ($class === null) {
                break;
            }

            $moons = $this->loadMoons($scope);
            $this->valuation->prepare($this->oreTypesIn($moons));
            $refineries = $this->refineryMoons();
            $claims = $this->claimedMoons();

            $better = [];
            foreach ($moons as $moon) {
                if ($moon['moon_id'] === $moonId) {
                    continue;
                }

                $rarityShares = $this->rarityShares($moon['ores']);
                if ($this->classFrom($rarityShares) !== $class) {
                    continue;
                }

                $candidate = $this->valuation->value($moon['ores'], $days);
                $value = $basis === 'refined' ? $candidate['refined'] : $candidate['raw'];
                if ($value <= $targetValue) {
                    continue;
                }

                $band = self::securityBand($moon['region_id'], $moon['security']);
                $marks = [
                    'station' => $refineries[$moon['moon_id']] ?? null,
                    'claim' => $claims[$moon['moon_id']] ?? null,
                ];
                $better[] = $this->row($moon, $candidate, $class, $rarityShares, $band, null, $value, $marks)
                    + ['difference' => round($value - $targetValue)];
            }

            if (!empty($better)) {
                $this->sortRows($better, 'value', 'desc');

                return ['class' => $class, 'scope' => $scopeInfo, 'days' => $days, 'moons' => array_slice($better, 0, 3)];
            }
        }

        return ['class' => $class, 'scope' => $scopeInfo, 'days' => $days, 'moons' => []];
    }

    /**
     * 28-day values of every scanned moon, sorted, per value basis and class.
     *
     * Rebuilt only when the prices, the settings that turn them into values,
     * the extraction model or the scans change.
     */
    public function classValues(): array
    {
        $key = 'mining-manager:moon-class-values:' . md5($this->valuation->fingerprint() . '|' . $this->scanFingerprint());

        return Cache::remember($key, 6 * 3600, function () {
            $values = [
                'ore' => array_fill_keys(self::CLASSES, []),
                'refined' => array_fill_keys(self::CLASSES, []),
            ];

            $moons = $this->loadMoons([]);
            $this->valuation->prepare($this->oreTypesIn($moons));

            foreach ($moons as $moon) {
                $class = $this->classFrom($this->rarityShares($moon['ores']));
                if ($class === null) {
                    continue;
                }

                $valued = $this->valuation->value($moon['ores'], self::QUALITY_DAYS);
                $values['ore'][$class][] = $valued['raw'];
                $values['refined'][$class][] = $valued['refined'];
            }

            foreach (array_keys($values) as $basis) {
                foreach (array_keys($values[$basis]) as $class) {
                    sort($values[$basis][$class]);
                }
            }

            return $values;
        });
    }

    /**
     * Where a 28-day value sits among the scanned moons of its class.
     *
     * @return array{key: string, class: string, top_percent: int, class_size: int}|null
     */
    public function quality(?string $class, float $value28, string $basis, ?array $classValues = null): ?array
    {
        if ($class === null) {
            return null;
        }

        $classValues = $classValues ?? $this->classValues();
        $sorted = $classValues[$basis][$class] ?? [];
        $count = count($sorted);
        if ($count < self::QUALITY_MIN_CLASS_SIZE) {
            return null;
        }

        // Room for floating point noise between a fresh valuation and the
        // stored one, so a moon is never ranked below its own value.
        $tolerance = max(1.0, abs($value28) * 1e-9);

        // Rounded up, so a moon never looks better than it is: the 11th best
        // of 105 shows as top 11%, not top 10%.
        $atOrAbove = $count - $this->countBelow($sorted, $value28 - $tolerance);
        $topPercent = max(1, min(100, (int) ceil(100 * $atOrAbove / $count)));

        $key = 'poor';
        foreach (self::QUALITY_BANDS as $band => $widest) {
            if ($topPercent <= $widest) {
                $key = $band;
                break;
            }
        }

        return [
            'key' => $key,
            'class' => $class,
            'top_percent' => $topPercent,
            'class_size' => $count,
        ];
    }

    public function scannedMoonCount(): int
    {
        return (int) DB::table('universe_moon_contents')->distinct()->count('moon_id');
    }

    /**
     * Regions holding at least one scanned moon.
     */
    public function regions(): array
    {
        return $this->placeQuery('region', [], '')
            ->get()
            ->map(function ($row) {
                return $this->placeRow('region', $row);
            })
            ->all();
    }

    /**
     * One page of constellations or systems holding at least one scanned
     * moon, for the location boxes.
     *
     * Each box works on its own. A region or constellation already picked
     * narrows the list, the text matches part of a name, and every place
     * carries the places above it, so picking a system can fill in its
     * constellation and region.
     *
     * @param array $within region_id and constellation_id, either optional
     * @return array{results: array, more: bool}
     */
    public function places(string $level, array $within = [], string $text = '', int $page = 1): array
    {
        $rows = $this->placeQuery($level, $within, $text)
            ->offset((max(1, $page) - 1) * self::PLACES_PER_PAGE)
            ->limit(self::PLACES_PER_PAGE + 1)
            ->get();

        return [
            'results' => $rows->take(self::PLACES_PER_PAGE)
                ->map(function ($row) use ($level) {
                    return $this->placeRow($level, $row);
                })
                ->values()
                ->all(),
            'more' => $rows->count() > self::PLACES_PER_PAGE,
        ];
    }

    /**
     * Moons one of our refineries still sits on, keyed by moon id.
     *
     * Extractions and planned pulls are what tie a refinery to a moon: both
     * name the moon and the structure. A structure SeAT no longer lists has
     * been unanchored or blown up, so its moon counts as free again, and a
     * structure id that has since been reused elsewhere only counts while it
     * is still a refinery.
     *
     * @return array<int, array{structure_id: int, structure: ?string, corporation_id: int, corporation: ?string}>
     */
    public function refineryMoons(): array
    {
        if ($this->refineryMoons !== null) {
            return $this->refineryMoons;
        }

        // Ours first, then planned, then what is only history, so a moon with
        // two refineries in its past names the one we are working it with.
        $tables = [
            'moon_extractions',
            'moon_extraction_plans',
            'moon_extraction_history',
            'corporation_industry_mining_extractions',
        ];

        $links = [];
        $structureIds = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->select('moon_id', 'structure_id')
                ->whereNotNull('moon_id')
                ->whereNotNull('structure_id')
                ->distinct()
                ->cursor();

            foreach ($rows as $row) {
                $links[(int) $row->moon_id][(int) $row->structure_id] = true;
                $structureIds[(int) $row->structure_id] = true;
            }
        }

        $anchored = $this->anchoredRefineries(array_keys($structureIds));

        $moons = [];
        foreach ($links as $moonId => $structures) {
            foreach (array_keys($structures) as $structureId) {
                if (isset($anchored[$structureId])) {
                    $moons[$moonId] = $anchored[$structureId];
                    break;
                }
            }
        }

        return $this->refineryMoons = $moons;
    }

    /**
     * Moons reported as held by somebody else, keyed by moon id.
     *
     * Only live claims: a moon found free again has its claim closed, and the
     * closed row stays for the history.
     *
     * @return array<int, array{claimed_by: ?string, note: ?string, reported_by: ?string, reported_at: ?string}>
     */
    public function claimedMoons(): array
    {
        if ($this->claimedMoons !== null) {
            return $this->claimedMoons;
        }

        if (!Schema::hasTable('mining_manager_moon_claims')) {
            return $this->claimedMoons = [];
        }

        $claims = [];
        $rows = DB::table('mining_manager_moon_claims')
            ->whereNull('cleared_at')
            ->orderBy('id')
            ->get(['moon_id', 'claimed_by', 'note', 'character_name', 'created_at']);

        foreach ($rows as $row) {
            $claims[(int) $row->moon_id] = [
                'claimed_by' => $row->claimed_by,
                'note' => $row->note,
                'reported_by' => $row->character_name,
                'reported_at' => $row->created_at === null ? null : (string) $row->created_at,
            ];
        }

        return $this->claimedMoons = $claims;
    }

    /**
     * Which of these structures SeAT still lists as a refinery, with its name
     * and the corporation holding it.
     */
    protected function anchoredRefineries(array $structureIds): array
    {
        $anchored = [];

        foreach (array_chunk($structureIds, 500) as $chunk) {
            $rows = DB::table('corporation_structures as cs')
                ->leftJoin('universe_structures as us', 'us.structure_id', '=', 'cs.structure_id')
                ->leftJoin('corporation_infos as ci', 'ci.corporation_id', '=', 'cs.corporation_id')
                ->whereIn('cs.structure_id', $chunk)
                ->whereIn('cs.type_id', MoonPlannerService::REFINERY_TYPE_IDS)
                ->get(['cs.structure_id', 'cs.corporation_id', 'us.name as structure_name', 'ci.name as corporation_name']);

            foreach ($rows as $row) {
                $anchored[(int) $row->structure_id] = [
                    'structure_id' => (int) $row->structure_id,
                    'structure' => $row->structure_name,
                    'corporation_id' => (int) $row->corporation_id,
                    'corporation' => $row->corporation_name,
                ];
            }
        }

        return $anchored;
    }

    /**
     * Scanned moons whose name contains the text, for the simulator's moon box.
     */
    public function moonsNamed(string $text, int $limit = 30): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return [];
        }

        return DB::table('moons as m')
            ->whereIn('m.moon_id', DB::table('universe_moon_contents')->select('moon_id'))
            ->where('m.name', 'like', '%' . addcslashes($text, '%_\\') . '%')
            ->orderBy('m.name')
            ->limit($limit)
            ->get(['m.moon_id', 'm.name'])
            ->map(function ($moon) {
                return ['id' => (int) $moon->moon_id, 'text' => $moon->name];
            })
            ->all();
    }

    /**
     * Moon ores that appear in at least one scan, rarest class first.
     */
    public function oreOptions(): array
    {
        $rarity = $this->rarityByType();

        $typeIds = [];
        foreach (DB::table('universe_moon_contents')->distinct()->pluck('type_id') as $typeId) {
            if (isset($rarity[(int) $typeId])) {
                $typeIds[] = (int) $typeId;
            }
        }

        if (empty($typeIds)) {
            return [];
        }

        $names = DB::table('invTypes')->whereIn('typeID', $typeIds)->pluck('typeName', 'typeID');

        $options = [];
        foreach ($typeIds as $typeId) {
            $options[] = [
                'id' => $typeId,
                'name' => (string) ($names[$typeId] ?? "Type {$typeId}"),
                'rarity' => $rarity[$typeId],
            ];
        }

        usort($options, function ($a, $b) {
            return [array_search($b['rarity'], self::CLASSES, true), $a['name']]
                <=> [array_search($a['rarity'], self::CLASSES, true), $b['name']];
        });

        return $options;
    }

    /**
     * EVE's security bands. Wormhole regions are numbered from 11000000; a
     * true security of 0.45 shows as 0.5 in game, and anything above zero
     * shows as at least 0.1.
     */
    public static function securityBand(int $regionId, float $security): string
    {
        if ($regionId >= 11000000) {
            return 'wormhole';
        }
        if ($security >= 0.45) {
            return 'high';
        }
        if ($security > 0.0) {
            return 'low';
        }

        return 'null';
    }

    /**
     * Scanned moons with location and ore shares, narrowed by any of
     * moon_id, region_id, constellation_id and system_id.
     *
     * @return array<int, array> moon id => moon
     */
    protected function loadMoons(array $where): array
    {
        $query = DB::table('universe_moon_contents as c')
            ->join('moons as m', 'm.moon_id', '=', 'c.moon_id')
            ->leftJoin('solar_systems as s', 's.system_id', '=', 'm.system_id')
            ->leftJoin('constellations as k', 'k.constellation_id', '=', 'm.constellation_id')
            ->leftJoin('regions as r', 'r.region_id', '=', 'm.region_id')
            ->select(
                'c.moon_id', 'c.type_id', 'c.rate',
                'm.name', 'm.system_id', 'm.constellation_id', 'm.region_id',
                's.name as system_name', 's.security',
                'k.name as constellation_name', 'r.name as region_name'
            )
            ->orderBy('c.moon_id');

        if (!empty($where['moon_id'])) {
            $query->where('c.moon_id', (int) $where['moon_id']);
        }
        foreach (['region_id', 'constellation_id', 'system_id'] as $column) {
            if (!empty($where[$column])) {
                $query->where('m.' . $column, (int) $where[$column]);
            }
        }

        $moons = [];
        foreach ($query->cursor() as $row) {
            $moonId = (int) $row->moon_id;
            if (!isset($moons[$moonId])) {
                $moons[$moonId] = [
                    'moon_id' => $moonId,
                    'name' => (string) $row->name,
                    'system_id' => (int) $row->system_id,
                    'system' => $row->system_name,
                    'security' => (float) ($row->security ?? 0.0),
                    'constellation_id' => (int) $row->constellation_id,
                    'constellation' => $row->constellation_name,
                    'region_id' => (int) $row->region_id,
                    'region' => $row->region_name,
                    'ores' => [],
                ];
            }
            $moons[$moonId]['ores'][(int) $row->type_id] = (float) $row->rate;
        }

        return $moons;
    }

    /**
     * Changes when a moon report is added, replaced or removed.
     */
    protected function scanFingerprint(): string
    {
        $reports = DB::table('universe_moon_reports')
            ->selectRaw('COUNT(*) as reports, MAX(updated_at) as updated')
            ->first();

        return md5(json_encode([
            $reports->reports ?? 0,
            $reports->updated ?? null,
            DB::table('universe_moon_contents')->count(),
        ]));
    }

    protected function matchesComposition(array $moon, array $rarityShares, ?string $class, array $criteria): bool
    {
        if (!empty($criteria['classes']) && !in_array($class, $criteria['classes'], true)) {
            return false;
        }

        // Shares are stored to two decimals, so compare in whole percent with
        // a little room for floating point.
        if ($criteria['richness_min'] !== null && array_sum($moon['ores']) * 100 + 1e-6 < $criteria['richness_min']) {
            return false;
        }

        foreach ($criteria['rules'] as $rule) {
            if ($rarityShares[$rule['rarity']] * 100 + 1e-6 < $rule['min']) {
                return false;
            }
        }

        foreach ($criteria['ores'] as $typeId) {
            if (($moon['ores'][$typeId] ?? 0) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $marks what we know about the moon beyond its scan: the
     *                     refinery of ours on it, and any claim reported on it
     */
    protected function row(array $moon, array $valued, ?string $class, array $rarityShares, string $band, ?array $quality, float $value, array $marks = []): array
    {
        $rarity = $this->rarityByType();

        $ores = [];
        foreach ($valued['ores'] as $line) {
            $ores[] = [
                'type_id' => $line['type_id'],
                'name' => $line['ore_name'],
                'percent' => round($line['share'] * 100, 1),
                'rarity' => $rarity[$line['type_id']] ?? null,
            ];
        }
        usort($ores, function ($a, $b) {
            return $b['percent'] <=> $a['percent'];
        });

        return [
            'moon_id' => $moon['moon_id'],
            'name' => $moon['name'],
            'system_id' => $moon['system_id'],
            'system' => $moon['system'],
            'security' => round($moon['security'], 3),
            'security_band' => $band,
            'constellation_id' => $moon['constellation_id'],
            'constellation' => $moon['constellation'],
            'region_id' => $moon['region_id'],
            'region' => $moon['region'],
            'class' => $class,
            'moon_ore_percent' => round($valued['share'] * 100, 1),
            'rarity_percent' => array_map(function ($share) {
                return round($share * 100, 1);
            }, $rarityShares),
            'ores' => $ores,
            'value' => round($value),
            'ore_value' => round($valued['raw']),
            'refined_value' => round($valued['refined']),
            'quality' => $quality,
            'station' => $marks['station'] ?? null,
            'claim' => $marks['claim'] ?? null,
        ];
    }

    /**
     * Sort by one column, ties broken by moon name so a page never shuffles.
     */
    protected function sortRows(array &$rows, string $sort, string $direction = 'desc'): void
    {
        $sign = $direction === 'asc' ? 1 : -1;

        usort($rows, function ($a, $b) use ($sort, $sign) {
            $order = $this->compareRows($a, $b, $sort);

            return $order === 0 ? strnatcasecmp($a['name'], $b['name']) : $order * $sign;
        });
    }

    /**
     * Compares two rows on one column, lowest first. A moon with nothing in
     * the column (no class, no rating) counts as the lowest.
     */
    protected function compareRows(array $a, array $b, string $sort): int
    {
        switch ($sort) {
            case 'name':
                return strnatcasecmp($a['name'], $b['name']);
            case 'system':
            case 'constellation':
            case 'region':
                return strnatcasecmp((string) $a[$sort], (string) $b[$sort]);
            case 'class':
                return $this->classOrder($a['class']) <=> $this->classOrder($b['class']);
            case 'share':
                return $a['moon_ore_percent'] <=> $b['moon_ore_percent'];
            case 'quality':
                return $this->qualityOrder($a['quality']) <=> $this->qualityOrder($b['quality']);
            default:
                return $a['value'] <=> $b['value'];
        }
    }

    /**
     * A class as something sortable, rarest highest. No class sorts lowest.
     */
    protected function classOrder(?string $class): int
    {
        $index = array_search($class, self::CLASSES, true);

        return $index === false ? -1 : (int) $index;
    }

    /**
     * A rating as something sortable: its band, then its place inside it.
     */
    protected function qualityOrder(?array $quality): array
    {
        if ($quality === null) {
            return [-1, 0];
        }

        return [(int) array_search($quality['key'], self::QUALITY_ORDER, true), -$quality['top_percent']];
    }

    protected function qualityAtLeast(?array $quality, string $minimum): bool
    {
        return $quality !== null
            && array_search($quality['key'], self::QUALITY_ORDER, true) >= array_search($minimum, self::QUALITY_ORDER, true);
    }

    /**
     * How many of the sorted values are below $value.
     */
    protected function countBelow(array $sorted, float $value): int
    {
        $low = 0;
        $high = count($sorted);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @return array<string, float> R4 to R64 => share of the moon
     */
    protected function rarityShares(array $ores): array
    {
        $rarity = $this->rarityByType();
        $shares = array_fill_keys(self::CLASSES, 0.0);

        foreach ($ores as $typeId => $share) {
            if (isset($rarity[$typeId])) {
                $shares[$rarity[$typeId]] += $share;
            }
        }

        return $shares;
    }

    /**
     * A moon's class is its rarest ore.
     */
    protected function classFrom(array $rarityShares): ?string
    {
        foreach (array_reverse(self::CLASSES) as $class) {
            if (($rarityShares[$class] ?? 0) > 0) {
                return $class;
            }
        }

        return null;
    }

    protected function rarityByType(): array
    {
        if ($this->rarityByType === null) {
            $this->rarityByType = [];
            foreach (TypeIdRegistry::getMoonOreRarityMap() as $class => $typeIds) {
                foreach ($typeIds as $typeId) {
                    $this->rarityByType[(int) $typeId] = $class;
                }
            }
        }

        return $this->rarityByType;
    }

    /**
     * @return int[]
     */
    protected function oreTypesIn(array $moons): array
    {
        $typeIds = [];
        foreach ($moons as $moon) {
            foreach (array_keys($moon['ores']) as $typeId) {
                $typeIds[$typeId] = true;
            }
        }

        return array_keys($typeIds);
    }

    /**
     * Which place a suggestion search covered, named for the page.
     */
    protected function describeScope(array $scope, array $target): array
    {
        if (isset($scope['constellation_id'])) {
            $name = (int) $scope['constellation_id'] === $target['constellation_id']
                ? $target['constellation']
                : DB::table('constellations')->where('constellation_id', $scope['constellation_id'])->value('name');

            return ['type' => 'constellation', 'id' => (int) $scope['constellation_id'], 'name' => $name];
        }

        $name = (int) $scope['region_id'] === $target['region_id']
            ? $target['region']
            : DB::table('regions')->where('region_id', $scope['region_id'])->value('name');

        return ['type' => 'region', 'id' => (int) $scope['region_id'], 'name' => $name];
    }

    /**
     * Regions, constellations or systems with their count of scanned moons
     * and the places above them, names starting with the text first.
     *
     * @param string $level region, constellation or system
     * @return \Illuminate\Database\Query\Builder
     */
    protected function placeQuery(string $level, array $within, string $text)
    {
        $query = DB::table('universe_moon_contents as c')
            ->join('moons as m', 'm.moon_id', '=', 'c.moon_id')
            ->join('regions as r', 'r.region_id', '=', 'm.region_id')
            ->select('m.region_id', 'r.name as region_name')
            ->selectRaw('COUNT(DISTINCT c.moon_id) as moons')
            ->groupBy('m.region_id', 'r.name');
        $nameColumn = 'r.name';

        if ($level !== 'region') {
            $query->join('constellations as k', 'k.constellation_id', '=', 'm.constellation_id')
                ->addSelect('m.constellation_id', 'k.name as constellation_name')
                ->groupBy('m.constellation_id', 'k.name');
            $nameColumn = 'k.name';
        }

        if ($level === 'system') {
            $query->join('solar_systems as s', 's.system_id', '=', 'm.system_id')
                ->addSelect('m.system_id', 's.name as system_name')
                ->groupBy('m.system_id', 's.name');
            $nameColumn = 's.name';
        }

        foreach (['region_id', 'constellation_id'] as $column) {
            if (!empty($within[$column])) {
                $query->where('m.' . $column, (int) $within[$column]);
            }
        }

        $text = trim($text);
        if ($text !== '') {
            $pattern = addcslashes($text, '%_\\');
            $query->where($nameColumn, 'like', '%' . $pattern . '%')
                ->orderByRaw('CASE WHEN ' . $nameColumn . ' LIKE ? THEN 0 ELSE 1 END', [$pattern . '%']);
        }

        return $query->orderBy($nameColumn);
    }

    protected function placeRow(string $level, $row): array
    {
        $place = [
            'id' => (int) $row->{$level . '_id'},
            'name' => (string) $row->{$level . '_name'},
            'moons' => (int) $row->moons,
        ];

        if ($level === 'system') {
            $place['constellation_id'] = (int) $row->constellation_id;
            $place['constellation'] = (string) $row->constellation_name;
        }

        if ($level !== 'region') {
            $place['region_id'] = (int) $row->region_id;
            $place['region'] = (string) $row->region_name;
        }

        return $place;
    }
}
