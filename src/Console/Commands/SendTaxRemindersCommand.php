<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Tax\TaxCalculationService;
use Carbon\Carbon;

class SendTaxRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:send-reminders
                            {--overdue-only : Only send reminders for overdue taxes}
                            {--days-overdue=7 : Days overdue before sending reminder}
                            {--dry-run : Show what would be sent without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send tax payment reminders to characters with unpaid taxes';

    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * @var SettingsManagerService
     */
    protected $settingsService;

    /**
     * @var TaxCalculationService
     */
    protected $taxService;

    /**
     * Create a new command instance.
     */
    public function __construct(
        NotificationService $notificationService,
        SettingsManagerService $settingsService,
        TaxCalculationService $taxService
    ) {
        parent::__construct();
        $this->notificationService = $notificationService;
        $this->settingsService = $settingsService;
        $this->taxService = $taxService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:send-reminders', 600);
        if (!$lock->get()) {
            $this->warn('Another instance of this command is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
        $this->info('Starting tax reminder process...');

        // Check if reminders are enabled in settings
        $remindersEnabled = (bool) $this->settingsService->getSetting('tax_rates.send_tax_reminders', true);
        if (!$remindersEnabled) {
            $this->info('Tax reminders are disabled in settings. Exiting.');
            return Command::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $overdueOnly = $this->option('overdue-only');
        $daysOverdue = $this->option('days-overdue');

        // Refresh tax statuses BEFORE selecting reminder targets. Without this,
        // status only flips unpaid→overdue when CalculateMonthlyTaxesCommand
        // runs (once per month), so newly-past-due taxes stayed marked
        // 'unpaid' for weeks and got the wrong notification flavor.
        // updateOverdueTaxes() respects the ESI Wallet Lag Buffer
        // (payment.grace_period_hours, default 24h) so legitimate payers
        // don't get false-positive overdue alerts while ESI is still
        // catching up to their wallet journal.
        if (!$dryRun) {
            try {
                $flipped = $this->taxService->updateOverdueTaxes();
                if ($flipped > 0) {
                    $this->info("Refreshed tax statuses: {$flipped} record(s) flipped unpaid → overdue.");
                }
            } catch (\Throwable $e) {
                $this->warn("Failed to refresh tax statuses: {$e->getMessage()}");
                Log::warning("SendTaxRemindersCommand: updateOverdueTaxes failed", ['error' => $e->getMessage()]);
            }
        }

        // Get the configured reminder days before deadline
        $reminderDaysBefore = (int) $this->settingsService->getSetting('tax_rates.tax_reminder_days', 3);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No notifications will be sent');
        }

        // Include both 'unpaid' (pre-grace-period) and 'overdue' (post-grace-period)
        // statuses. Pre-fix, this only queried 'unpaid', which meant:
        //   (a) taxes past due but still within the 7-day grace period got
        //       tax_reminder spam with "Days Remaining: 0" every day.
        //   (b) taxes past the grace period (status flipped to 'overdue' by
        //       TaxCalculationService::updateOverdueTaxes) fell out of the
        //       query entirely and got NO notifications at all.
        // Both are fixed here — the per-character loop below branches between
        // sendTaxReminder and sendTaxOverdue based on each character's
        // earliest due date vs today.
        $query = MiningTax::whereIn('status', ['unpaid', 'overdue'])
            ->where('amount_owed', '>', 0);

        if ($overdueOnly) {
            $overdueDate = Carbon::now()->subDays($daysOverdue);
            $query->where(function ($q) use ($overdueDate) {
                // Use due_date if set
                $q->where(function ($inner) use ($overdueDate) {
                    $inner->whereNotNull('due_date')->where('due_date', '<', $overdueDate);
                })->orWhere(function ($inner) use ($overdueDate) {
                    // Fallback for records without due_date: period_end + grace
                    $inner->whereNull('due_date')
                          ->whereNotNull('period_end')
                          ->where('period_end', '<', $overdueDate);
                });
            });
            $this->info("Sending reminders for taxes overdue by {$daysOverdue}+ days");
        } else {
            // Send reminders for taxes whose due date is within the reminder window or already past
            $reminderThreshold = Carbon::now()->addDays($reminderDaysBefore);
            $query->where(function ($q) use ($reminderThreshold) {
                $q->where(function ($inner) use ($reminderThreshold) {
                    $inner->whereNotNull('due_date')->where('due_date', '<=', $reminderThreshold);
                })->orWhere(function ($inner) use ($reminderThreshold) {
                    // Fallback for records without due_date
                    $inner->whereNull('due_date')
                          ->whereNotNull('period_end')
                          ->where('period_end', '<', Carbon::now()->startOfDay());
                });
            });
            $this->info("Sending reminders for taxes due within {$reminderDaysBefore} days (or overdue)");
        }

        $unpaidTaxes = $query->get();

        if ($unpaidTaxes->isEmpty()) {
            $this->warn('No unpaid/overdue taxes found needing notifications');
            return Command::SUCCESS;
        }

        $this->info("Found {$unpaidTaxes->count()} tax record(s) eligible for notification");

        // Group by character to send one notification per character
        $taxesByCharacter = $unpaidTaxes->groupBy('character_id');

        $sentReminder = 0;
        $sentOverdue = 0;
        $errors = 0;

        foreach ($taxesByCharacter as $characterId => $taxes) {
            try {
                $totalOwed = $taxes->sum('amount_owed');
                $taxCount = $taxes->count();

                // Find the earliest due date among this character's outstanding taxes
                $earliestDueDate = $taxes->min('due_date');
                $dueDate = Carbon::parse($earliestDueDate);
                $dueDateOnly = $dueDate->copy()->startOfDay();

                // Branch on STATUS (not raw date) so we stay aligned with
                // TaxCalculationService::updateOverdueTaxes — which flips
                // the status only after the ESI Wallet Lag Buffer has
                // elapsed. A tax whose due_date is past but whose status is
                // still 'unpaid' (because ESI might still be delivering a
                // payment) correctly gets a reminder, not a premature
                // overdue notice.
                $isOverdue = $taxes->contains(fn($t) => $t->status === 'overdue');
                $today = Carbon::now()->startOfDay();

                if ($isOverdue) {
                    // Signed diff from due-date to today. If today > dueDate
                    // (the normal overdue case), this is positive = days past
                    // due. max(0, ...) is defensive — should never be negative
                    // when status='overdue' but guards against clock skew.
                    $daysOverdueForChar = max(0, (int) $dueDateOnly->diffInDays($today, false));

                    if ($dryRun) {
                        $this->line("Would send OVERDUE to character {$characterId}:");
                        $this->line("  - Total owed: " . number_format($totalOwed, 2) . " ISK");
                        $this->line("  - Outstanding records: {$taxCount}");
                        $this->line("  - Earliest due: " . $dueDate->format('Y-m-d') . " ({$daysOverdueForChar} days overdue)");
                        $sentOverdue++;
                        continue;
                    }

                    $result = $this->notificationService->sendTaxOverdue(
                        (int) $characterId,
                        (float) $totalOwed,
                        $dueDate,
                        $daysOverdueForChar
                    );

                    if ($result) {
                        DB::transaction(function () use ($taxes) {
                            foreach ($taxes as $tax) {
                                $tax->update([
                                    'last_reminder_sent' => Carbon::now(),
                                    'reminder_count' => ($tax->reminder_count ?? 0) + 1,
                                ]);
                            }
                        });

                        $this->line("Sent OVERDUE to character {$characterId}: " .
                                   number_format($totalOwed, 2) . " ISK owed, due " . $dueDate->format('Y-m-d') .
                                   " ({$daysOverdueForChar} days overdue)");
                        $sentOverdue++;
                    } else {
                        $this->warn("Failed to send overdue notification to character {$characterId}");
                        $errors++;
                    }
                } else {
                    // Use SIGNED diff so dates in the past return negative,
                    // then clamp to 0. Without `false` Carbon returns
                    // abs($a - $b) which (1) goes positive again past the
                    // due date and (2) grows by one each day — produces
                    // bogus "Days Remaining: 5" pings when actually 5 days
                    // past due. The real overdue path is in the if-branch
                    // above; this else-branch only fires for unpaid taxes
                    // still within the ESI grace period (typically 24h
                    // post due date), where 0 is the correct display.
                    $daysRemaining = max(0, (int) $today->diffInDays($dueDateOnly, false));

                    if ($dryRun) {
                        $this->line("Would send REMINDER to character {$characterId}:");
                        $this->line("  - Total owed: " . number_format($totalOwed, 2) . " ISK");
                        $this->line("  - Outstanding records: {$taxCount}");
                        $this->line("  - Earliest due: " . $dueDate->format('Y-m-d') . " ({$daysRemaining} days remaining)");
                        $sentReminder++;
                        continue;
                    }

                    $result = $this->notificationService->sendTaxReminder(
                        (int) $characterId,
                        (float) $totalOwed,
                        $dueDate,
                        $daysRemaining
                    );

                    if ($result) {
                        DB::transaction(function () use ($taxes) {
                            foreach ($taxes as $tax) {
                                $tax->update([
                                    'last_reminder_sent' => Carbon::now(),
                                    'reminder_count' => ($tax->reminder_count ?? 0) + 1,
                                ]);
                            }
                        });

                        $this->line("Sent REMINDER to character {$characterId}: " .
                                   number_format($totalOwed, 2) . " ISK owed, due " . $dueDate->format('Y-m-d') .
                                   " ({$daysRemaining} days remaining)");
                        $sentReminder++;
                    } else {
                        $this->warn("Failed to send reminder to character {$characterId}");
                        $errors++;
                    }
                }

            } catch (\Exception $e) {
                $this->error("Error sending notification to character {$characterId}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("\nNotification process complete!");
        $this->info("Reminders sent: {$sentReminder}");
        $this->info("Overdue notices sent: {$sentOverdue}");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        if ($dryRun && ($sentReminder + $sentOverdue) > 0) {
            $this->info("\nRun without --dry-run to actually send these notifications");
        }

        return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
