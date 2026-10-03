<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Character\AffiliationResolutionService;

/**
 * ResolveGuestAffiliationsCommand
 *
 * Scheduled background job that resolves affiliation data for miners
 * who are not in SeAT's character_affiliations table.
 *
 * This replaces the on-request ESI resolution that was causing
 * dashboard slowdowns when dozens of unknown characters appeared
 * (e.g. after a moon pop with visiting pilots).
 *
 * Usage:
 *   php artisan mining-manager:resolve-guest-affiliations          # normal run
 *   php artisan mining-manager:resolve-guest-affiliations --all    # resolve all unresolved
 *   php artisan mining-manager:resolve-guest-affiliations --stats  # show cache stats
 */
class ResolveGuestAffiliationsCommand extends Command
{
    protected $signature = 'mining-manager:resolve-guest-affiliations
                            {--all : Resolve every unresolved character (not just guests)}
                            {--stats : Show cache statistics instead of resolving}
                            {--batch-size= : Override the default batch size (20)}';

    protected $description = 'Resolve and cache guest miner affiliations in the background';

    public function handle(AffiliationResolutionService $resolver): int
    {
        // Stats mode
        if ($this->option('stats')) {
            $stats = $resolver->getStats();
            $this->info('Character Affiliation Cache');
            $this->newLine();
            $this->line("  Total entries : {$stats['total']}");
            $this->line("  Valid         : {$stats['valid']}");
            $this->line("  Expired       : {$stats['expired']}");
            return Command::SUCCESS;
        }

        // Get home corporation IDs for filtering
        $settings = config('seat.mining-manager', []);
        $homeCorpIds = $settings['home_corporation_ids'] ?? [];

        if (empty($homeCorpIds)) {
            // Fall back to the moon owner corporation
            $homeCorpIds = [
                (int) DB::table('mining_manager_settings')
                    ->where('key', 'general.moon_owner_corporation_id')
                    ->value('value'),
            ];
            $homeCorpIds = array_filter($homeCorpIds);
        }

        $this->info("Home corporation IDs: " . implode(', ', $homeCorpIds));

        // Find unresolved guests (characters not in character_affiliations,
        // not locally cached, and not already known guests)
        $unresolvedIds = $resolver->findUnresolvedGuests($homeCorpIds);

        if ($this->option('all')) {
            // In --all mode, also include characters that have an affiliation
            // but whose local cache has expired — force re-resolve them
            $cachedIds = DB::table('mining_manager_character_affiliations')
                ->where('expires_at', '<', now())
                ->whereNotNull('expires_at')
                ->pluck('character_id')
                ->toArray();
            $allMinerIds = DB::table('mining_ledger')
                ->distinct()
                ->pluck('character_id')
                ->toArray();
            $unresolvedIds = array_values(array_diff($allMinerIds, $cachedIds));
        }

        if (empty($unresolvedIds)) {
            $this->info("No unresolved guests to process.");
            return Command::SUCCESS;
        }

        $this->info("Found " . count($unresolvedIds) . " unresolved character(s) to resolve.");

        $batchSize = (int) $this->option('batch-size') ?: AffiliationResolutionService::RESOLVE_BATCH_SIZE;
        $resolved = 0;
        $failed = 0;

        foreach (array_chunk($unresolvedIds, $batchSize) as $batchIndex => $chunk) {
            $this->task(
                "Resolving batch " . ($batchIndex + 1) . "/" . ceil(count($unresolvedIds) / $batchSize) .
                " (" . count($chunk) . " characters)",
                function () use ($chunk, $resolver, &$resolved, &$failed) {
                    $results = $resolver->resolveBatch($chunk);

                    foreach ($results as $charId => $result) {
                        if ($result['resolved'] && $result['corporation_id']) {
                            $resolved++;
                        } else {
                            $failed++;
                        }
                    }
                }
            );

            // Small delay between batches
            if ($batchIndex + 1 < count(array_chunk($unresolvedIds, $batchSize))) {
                usleep(500000); // 500ms between batches
            }
        }

        $this->newLine();
        $this->info("Resolution complete.");
        $this->line("  Resolved : {$resolved}");
        $this->line("  Failed   : {$failed}");

        // Show cache stats
        $stats = $resolver->getStats();
        $this->line("  Cache entries: {$stats['total']} ({$stats['valid']} valid)");

        return Command::SUCCESS;
    }
}
