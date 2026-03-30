<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Notification\NotificationService;
use MiningManager\Services\Configuration\SettingsManagerService;
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
     * Create a new command instance.
     */
    public function __construct(NotificationService $notificationService, SettingsManagerService $settingsService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
        $this->settingsService = $settingsService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
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

        // Get the configured reminder days before deadline
        $reminderDaysBefore = (int) $this->settingsService->getSetting('tax_rates.tax_reminder_days', 3);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No notifications will be sent');
        }

        // Build query for unpaid taxes with completed periods
        $query = MiningTax::where('status', 'unpaid')
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
            $this->warn('No unpaid taxes found needing reminders');
            return Command::SUCCESS;
        }

        $this->info("Found {$unpaidTaxes->count()} unpaid tax records");

        // Group by character to send one notification per character
        $taxesByCharacter = $unpaidTaxes->groupBy('character_id');

        $sent = 0;
        $errors = 0;

        foreach ($taxesByCharacter as $characterId => $taxes) {
            try {
                $totalOwed = $taxes->sum('amount_owed');
                $taxCount = $taxes->count();

                // Find the earliest due date among unpaid taxes for this character
                $earliestDueDate = $taxes->min('due_date');
                $dueDate = Carbon::parse($earliestDueDate);
                $daysRemaining = (int) max(0, Carbon::now()->startOfDay()->diffInDays($dueDate->startOfDay(), false));

                if ($dryRun) {
                    $this->line("Would send reminder to character {$characterId}:");
                    $this->line("  - Total owed: " . number_format($totalOwed, 2) . " ISK");
                    $this->line("  - Unpaid months: {$taxCount}");
                    $this->line("  - Earliest due: " . $dueDate->format('Y-m-d') . " ({$daysRemaining} days remaining)");
                    $sent++;
                    continue;
                }

                // Send notification with correct signature: (int $characterId, float $amount, Carbon $dueDate, int $daysRemaining)
                $result = $this->notificationService->sendTaxReminder(
                    (int) $characterId,
                    (float) $totalOwed,
                    $dueDate,
                    $daysRemaining
                );

                if ($result) {
                    // Update last reminder sent timestamp
                    foreach ($taxes as $tax) {
                        $tax->update([
                            'last_reminder_sent' => Carbon::now(),
                            'reminder_count' => ($tax->reminder_count ?? 0) + 1,
                        ]);
                    }

                    $this->line("Sent reminder to character {$characterId}: " .
                               number_format($totalOwed, 2) . " ISK owed, due " . $dueDate->format('Y-m-d'));
                    $sent++;
                } else {
                    $this->warn("Failed to send reminder to character {$characterId}");
                    $errors++;
                }

            } catch (\Exception $e) {
                $this->error("Error sending reminder to character {$characterId}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("\nReminder process complete!");
        $this->info("Sent: {$sent} reminders");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        if ($dryRun && $sent > 0) {
            $this->info("\nRun without --dry-run to actually send these reminders");
        }

        return Command::SUCCESS;
    }
}
