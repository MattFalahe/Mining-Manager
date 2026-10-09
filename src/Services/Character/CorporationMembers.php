<?php

namespace MiningManager\Services\Character;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * Who mines for a corporation, the same answer on every page that filters by
 * one: the dashboard, Analytics, the ledger and the character pickers.
 *
 * Its members as SeAT knows them, everyone whose mining was recorded under it,
 * and everyone seen at the moon owner's observers, less anyone known to be in
 * a corporation outside the ones this install looks after. A character nobody
 * has placed yet stays in: an unregistered member is far likelier than a
 * stranger, and the background lookup places them soon enough.
 */
class CorporationMembers
{
    private SettingsManagerService $settings;

    private AffiliationResolutionService $resolver;

    /** @var array<int, array<int, int>> corporation_id => character ids, for this request */
    private array $members = [];

    public function __construct(SettingsManagerService $settings, AffiliationResolutionService $resolver)
    {
        $this->settings = $settings;
        $this->resolver = $resolver;
    }

    /**
     * @return array<int, int>
     */
    public function characterIds(int $corporationId): array
    {
        if ($corporationId <= 0) {
            return [];
        }

        return $this->members[$corporationId] ??= $this->find($corporationId);
    }

    /**
     * @return array<int, int>
     */
    private function find(int $corporationId): array
    {
        $ids = [];

        // Members SeAT has placed in the corporation.
        $ids = array_merge($ids, $this->pluck('character affiliations', fn () => DB::table('character_affiliations')
            ->where('corporation_id', $corporationId)
            ->pluck('character_id')));

        // Mining recorded under the corporation.
        $ids = array_merge($ids, $this->pluck('mining ledger', fn () => DB::table('mining_ledger')
            ->where('corporation_id', $corporationId)
            ->distinct()
            ->pluck('character_id')));

        // Anyone seen at the moon owner's structures.
        $observerCorporationId = $this->settings->getSetting('general.moon_owner_corporation_id') ?: $corporationId;
        $ids = array_merge($ids, $this->pluck('mining observers', fn () => DB::table('corporation_industry_mining_observer_data as d')
            ->join('corporation_industry_mining_observers as o', 'd.observer_id', '=', 'o.observer_id')
            ->where('o.corporation_id', $observerCorporationId)
            ->distinct()
            ->pluck('d.character_id')));

        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }

        // Less anyone known to be somewhere else: SeAT's own affiliations
        // first, the plugin's lookups for the characters SeAT has none for.
        $home = $this->settings->getHomeCorporationIds() ?: [$corporationId];

        $elsewhere = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $elsewhere = array_merge($elsewhere, $this->pluck('character affiliations', fn () => DB::table('character_affiliations')
                ->whereIn('character_id', $chunk)
                ->whereNotIn('corporation_id', $home)
                ->pluck('character_id')));
        }
        $elsewhere = array_merge($elsewhere, $this->resolver->outsideHome($ids, $home));

        return array_values(array_diff($ids, $elsewhere));
    }

    /**
     * @return array<int, int>
     */
    private function pluck(string $what, \Closure $query): array
    {
        try {
            return $query()->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            Log::warning("Mining Manager: could not read {$what} for corporation members: " . $e->getMessage());

            return [];
        }
    }
}
