<?php

namespace MiningManager\Services\Moon;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use MiningManager\Models\MoonClaim;

/**
 * Keeps the claims on moons honest.
 *
 * A moon marked as somebody else's can end up ours: the structure is bought,
 * or they leave and we anchor our own. Nobody goes back to the search to tidy
 * that up, so the claim would sit there hiding a moon we are working. Once an
 * extraction ties one of our refineries to the moon, the claim is closed with
 * the reason recorded, rather than left for someone to notice.
 */
class MoonClaimService
{
    /**
     * Stands in for a person in cleared_by_name, so the history says why the
     * claim went away rather than leaving a blank.
     */
    public const CLEARED_BY_US = 'Mining Manager (our refinery drills it)';

    /**
     * @var MoonFinderService
     */
    protected $finder;

    public function __construct(MoonFinderService $finder)
    {
        $this->finder = $finder;
    }

    /**
     * Close the claims on moons one of our refineries now drills.
     *
     * @return int how many claims were closed
     */
    public function closeClaimsOnOurMoons(): int
    {
        if (!Schema::hasTable('mining_manager_moon_claims')) {
            return 0;
        }

        $ourMoons = array_keys($this->finder->refineryMoons());
        if (empty($ourMoons)) {
            return 0;
        }

        $closed = 0;
        foreach (array_chunk($ourMoons, 500) as $chunk) {
            $closed += MoonClaim::whereNull('cleared_at')
                ->whereIn('moon_id', $chunk)
                ->update([
                    'cleared_at' => Carbon::now(),
                    'cleared_by' => null,
                    'cleared_by_name' => self::CLEARED_BY_US,
                ]);
        }

        return $closed;
    }
}
