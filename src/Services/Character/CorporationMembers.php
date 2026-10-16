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
 *
 * Asked about a corporation outside those, such as a guest corporation picked
 * on Analytics, it answers with the characters placed in that corporation.
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
        $home = array_map('intval', $this->settings->getHomeCorporationIds() ?: [$corporationId]);

        // A corporation this install does not look after owns none of the
        // structures here and has no unregistered members to keep, so its
        // people are the characters placed in it. Put through the rule below,
        // it would get the home corporations' miners instead of its own.
        if (!in_array($corporationId, $home, true)) {
            return $this->placedIn($corporationId);
        }

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
     * Characters placed in a corporation: SeAT's affiliations, then the
     * plugin's lookups for the characters SeAT has none for.
     *
     * @return array<int, int>
     */
    private function placedIn(int $corporationId): array
    {
        $ids = $this->pluck('character affiliations', fn () => DB::table('character_affiliations')
            ->where('corporation_id', $corporationId)
            ->pluck('character_id'));

        $ids = array_merge($ids, $this->pluck('looked-up characters', fn () => DB::table(AffiliationResolutionService::TABLE)
            ->where('corporation_id', $corporationId)
            ->whereNotIn('character_id', DB::table('character_affiliations')->select('character_id'))
            ->pluck('character_id')));

        return array_values(array_unique($ids));
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
