<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Services\Character\AffiliationResolutionService;

/**
 * Looks up names and corporations for characters SeAT does not know, so pages
 * can show them without calling out to anything.
 *
 * With --requested it only takes the characters pages have asked for, which
 * is quick enough to run every minute. A full run also takes miners SeAT has
 * no affiliation for, and answers due another look.
 */
class ResolveCharactersCommand extends Command
{
    protected $signature = 'mining-manager:resolve-characters
                            {--requested : Only characters pages have asked for}
                            {--stats : Show what the table holds instead of looking anything up}';

    protected $description = 'Look up names and corporations for characters SeAT does not know, in the background';

    public function handle(AffiliationResolutionService $resolver): int
    {
        if ($this->option('stats')) {
            foreach ($resolver->stats() as $source => $total) {
                $this->line(sprintf('  %-12s %d', $source, $total));
            }

            return Command::SUCCESS;
        }

        $lock = Cache::lock('mining-manager:resolve-characters', 600);
        if (!$lock->get()) {
            $this->warn('Another lookup is already running. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $due = $resolver->due((bool) $this->option('requested'));

            if (!$due) {
                $this->info('Nobody to look up.');

                return Command::SUCCESS;
            }

            $counts = $resolver->resolve($due);

            $this->info(sprintf(
                'Looked up %d character(s): %d from ESI, %d from EVEWho or zKillboard, %d rejected by ESI, %d still to do.',
                count($due),
                $counts['esi'],
                $counts['fallback'],
                $counts['invalid'],
                $counts['left']
            ));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
