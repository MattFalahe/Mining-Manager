<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use MiningManager\Models\MiningTax;
use MiningManager\Services\Notification\NotificationService;
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
     * Notification service
     *
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new command instance.
     *
     * @param NotificationService $notificationService
     */
    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting tax reminder process...');

        $dryRun = $this->option('dry-run');
        $overdueOnly = $this->option('overdue-only');
        $daysOverdue = $this->option('days-overdue');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No notifications will be sent');
        }

        // Build query for unpaid taxes
        $query = MiningTax::where('status', 'unpaid')
            ->where('amount_owed', '>', 0);

        if ($overdueOnly) {
            // Consider tax overdue if it's for a month that ended more than X days ago
            $overdueDate = Carbon::now()->subDays($daysOverdue);
            $query->whereRaw('DATE_ADD(month, INTERVAL 1 MONTH) < ?', [$overdueDate]);
            $this->info("Sending reminders for taxes overdue by {$daysOverdue}+ days");
        } else {
            $this->info("Sending reminders for all unpaid taxes");
        }

        $unpaidTaxes = $query->get();

        if ($unpaidTaxes->isEmpty()) {
            $this->warn('No unpaid taxes found');
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
                $oldestMonth = $taxes->min('month');
                $taxCount = $taxes->count();

                if ($dryRun) {
                    $this->line("Would send reminder to character {$characterId}:");
                    $this->line("  - Total owed: " . number_format($totalOwed, 2) . " ISK");
                    $this->line("  - Unpaid months: {$taxCount}");
                    $this->line("  - Oldest: " . Carbon::parse($oldestMonth)->format('Y-m'));
                    $sent++;
                    continue;
                }

                // Send notification
                $result = $this->notificationService->sendTaxReminder(
                    $characterId,
                    $totalOwed,
                    $taxCount,
                    $oldestMonth
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
                               number_format($totalOwed, 2) . " ISK owed");
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
