<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MiningManager\Services\Moon\MoonExtractionService;
use Seat\Eveapi\Models\Corporation\CorporationStructure;
use Carbon\Carbon;

class UpdateMoonExtractionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:update-extractions
                            {--structure_id= : Update specific structure}
                            {--corporation_id= : Update structures for specific corporation}
                            {--active-only : Only update active extractions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update moon extraction data from corporation structures';

    /**
     * Moon extraction service
     *
     * @var MoonExtractionService
     */
    protected $extractionService;

    /**
     * Create a new command instance.
     *
     * @param MoonExtractionService $extractionService
     */
    public function __construct(MoonExtractionService $extractionService)
    {
        parent::__construct();
        $this->extractionService = $extractionService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Mutex lock — schedule entry has allow_overlap: false but that
        // only serialises the same schedule. Manual artisan runs or other
        // invocation paths can still overlap a running cron. Cache::lock
        // gives process-wide serialisation; matches the pattern used by
        // ProcessMiningLedgerCommand, DetectJackpotsCommand, etc.
        $lock = Cache::lock('mining-manager:update-extractions', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return self::SUCCESS;
        }

        try {
            return $this->handleWithLock();
        } finally {
            $lock->release();
        }
    }

    private function handleWithLock(): int
    {
        // Check feature flag
        $settingsService = app(\MiningManager\Services\Configuration\SettingsManagerService::class);
        $features = $settingsService->getFeatureFlags();
        if (!($features['enable_moon_tracking'] ?? true)) {
            $this->info('Feature disabled in settings. Skipping.');
            return Command::SUCCESS;
        }

        $this->info('Starting moon extraction update...');

        // Build query for refineries (Athanor + Tatara)
        $query = CorporationStructure::whereIn('type_id', [35835, 35836]);

        if ($structureId = $this->option('structure_id')) {
            $query->where('structure_id', $structureId);
            $this->info("Updating structure ID: {$structureId}");
        }

        if ($corporationId = $this->option('corporation_id')) {
            $query->where('corporation_id', $corporationId);
            $this->info("Updating structures for corporation ID: {$corporationId}");
        }

        $structures = $query->get();

        if ($structures->isEmpty()) {
            $this->warn('No refineries found');
            return Command::SUCCESS;
        }

        $this->info("Found {$structures->count()} refinery structures");

        $updated = 0;
        $created = 0;
        $errors = 0;

        foreach ($structures as $structure) {
            try {
                $this->line("Processing structure: {$structure->name}");

                // The same import the Refresh button runs, so the two can never
                // drift apart again.
                $result = $this->extractionService->updateStructureExtractions($structure);

                if ($result['updated'] === 0 && $result['created'] === 0) {
                    $this->line("  No active extractions");
                    continue;
                }

                $this->line("  Updated {$result['updated']}, created {$result['created']}");
                $updated += $result['updated'];
                $created += $result['created'];

            } catch (\Exception $e) {
                $this->error("Error processing structure {$structure->name}: {$e->getMessage()}");
                $errors++;
            }
        }

        // Update status of past extractions — delegated to the service so that
        // moon arrival notifications fire on the extracting → ready transition.
        // (Previously this command had a private updatePastExtractions() that
        // duplicated the status flip without calling the notification dispatcher,
        // causing moon arrival notifications to silently never fire.)
        $this->extractionService->updateExtractionStatuses();

        // Planner reconciliation — now that ESI extraction data is fresh, pair
        // planned pulls with the real ones and fire `schedule_mismatch` for any
        // moon whose in-game timer diverged from the plan ("wrong scheduled
        // moon"). One-shot per plan; no-ops when no Moon Owner Corp is set.
        try {
            $moonOwnerCorpId = $settingsService->getTaxProgramCorporationId();
            if ($moonOwnerCorpId !== null) {
                $planner = app(\MiningManager\Services\Moon\MoonPlannerService::class);
                $planner->reconcile($moonOwnerCorpId);
                $mismatches = $planner->detectAndNotifyMismatches($moonOwnerCorpId);
                if ($mismatches > 0) {
                    $this->warn("Fired {$mismatches} schedule-mismatch notification(s).");
                }

                $reminders = app(\MiningManager\Services\Moon\PlannerReminders::class);
                $notifications = app(\MiningManager\Services\Notification\NotificationService::class);
                $plannerUrl = rtrim(config('app.url', ''), '/') . '/mining-manager/moon/planner';

                // One message per idle refinery: each is its own job for somebody.
                $idle = $reminders->notRescheduled($moonOwnerCorpId);
                foreach ($idle as $refinery) {
                    try {
                        $notifications->sendMoonNotRescheduled($refinery + ['planner_url' => $plannerUrl]);
                    } catch (\Throwable $e) {
                        $this->error("Moon Not Rescheduled failed for structure {$refinery['structure_id']}: {$e->getMessage()}");
                    }
                }
                if ($idle) {
                    $this->warn('Sent ' . count($idle) . ' Moon Not Rescheduled reminder(s).');
                }

                // An extraction started on a refinery whose last pull was
                // marked final: told to the planner's channel, once.
                foreach ($reminders->restartedAfterFinal($moonOwnerCorpId) as $restart) {
                    try {
                        $notifications->sendNextExtractionPlanned($restart + ['planner_url' => $plannerUrl]);
                    } catch (\Throwable $e) {
                        $this->error("Restart warning failed for structure {$restart['structure_id']}: {$e->getMessage()}");
                    }
                }

                // One message for every refinery short of planned pulls. Only
                // marked as sent when the send went through, so a failure is
                // tried again on the next run rather than a day later.
                $planning = $reminders->needsPlanning($moonOwnerCorpId);
                if ($planning) {
                    try {
                        $notifications->sendScheduleNeedsFilling($planning + ['planner_url' => $plannerUrl]);
                        $reminders->markNeedsPlanningSent();
                        $this->warn("Sent Moons Need Planning for {$planning['total']} refinery(ies).");
                    } catch (\Throwable $e) {
                        $this->error("Moons Need Planning failed: {$e->getMessage()}");
                    }
                }
            }
        } catch (\Exception $e) {
            // Planner reconciliation must never break the extraction import.
            $this->error("Planner reconciliation failed: {$e->getMessage()}");
        }

        // Moons we drill with no scan in SeAT are valued from the game's notices
        // instead, and the simulator cannot see them. Say so once per new
        // reason, and daily as well if that is switched on.
        try {
            $moonOwnerCorpId = $settingsService->getTaxProgramCorporationId();
            if ($moonOwnerCorpId !== null) {
                $listed = app(\MiningManager\Services\Moon\MoonScanWatch::class)->run((int) $moonOwnerCorpId);
                if ($listed > 0) {
                    $this->warn("Sent Moon Scan Missing for {$listed} moon(s).");
                }
            }
        } catch (\Throwable $e) {
            $this->error("Moon scan watch failed: {$e->getMessage()}");
        }

        // Pulls planned on a refinery we no longer own cannot happen, so they
        // come off the calendar, but only once it has been missing on three
        // sightings twelve hours apart. A structure can drop out of SeAT for an
        // afternoon. Blueprint slots stay until a person clears them.
        try {
            $gone = app(\MiningManager\Services\Moon\MissingRefineryWatch::class)->run();

            if ($gone) {
                $notifications = app(\MiningManager\Services\Notification\NotificationService::class);
                $plannerUrl = rtrim(config('app.url', ''), '/') . '/mining-manager/moon/planner';

                // One message per refinery, never grouped: losing a lot of them
                // at once is exactly when a combined message would be too big
                // for Discord and never arrive.
                foreach ($gone as $refinery) {
                    try {
                        $notifications->sendRefineryGone($refinery + ['planner_url' => $plannerUrl]);
                    } catch (\Throwable $e) {
                        $this->error("Refinery Gone notification failed for structure {$refinery['structure_id']}: {$e->getMessage()}");
                    }
                }

                $pulls = array_sum(array_column($gone, 'pulls_removed'));
                $this->warn("Removed {$pulls} planned pull(s) on " . count($gone) . " refinery(ies) that are gone.");
            }
        } catch (\Exception $e) {
            $this->error("Missing refinery watch failed: {$e->getMessage()}");
        }

        // A moon marked as somebody else's, or one somebody was waiting for,
        // is ours once one of our refineries drills it. Tidy both up here,
        // where the extraction that proves it has just been imported.
        try {
            $flags = app(\MiningManager\Services\Moon\MoonFlagService::class);

            $cleared = $flags->closeClaimsOnOurMoons();
            if ($cleared > 0) {
                $this->info("Cleared {$cleared} moon claim(s) on moons we now drill.");
            }

            $unwatched = $flags->unwatchOurMoons();
            if ($unwatched > 0) {
                $this->info("Took {$unwatched} moon(s) we now drill off the watchlist.");
            }
        } catch (\Exception $e) {
            $this->error("Moon flag cleanup failed: {$e->getMessage()}");
        }

        $this->info("\nMoon extraction update complete!");
        $this->info("Created: {$created} new extractions");
        $this->info("Updated: {$updated} existing extractions");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return Command::SUCCESS;
    }


}
