<?php

namespace MiningManager\Services\Configuration;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Notification\NotificationService;

/**
 * Saying once that settings moved into tax profiles.
 *
 * The migration only records the move: a webhook that is slow or down while
 * the container starts must not fail it. The scheduled price refresh sends
 * the message on its next run instead, to the webhooks that take Plugin
 * Health alerts, so it only goes where news about the plugin is already
 * wanted. It goes out once, and a send that reaches nobody is tried again on
 * the next few runs.
 *
 * Until it has gone out, admins see the same on the settings page and the
 * director dashboard, for two weeks after the move.
 */
class SettingsMovedNotice
{
    public const SENT_KEY = 'settings.transfer_announced_at';
    public const TRIES_KEY = 'settings.transfer_announce_tries';

    /** Runs a send that reached nobody is tried on, four hours apart. */
    public const TRIES = 6;

    /** Days after the move that it is still news. */
    public const DAYS = 14;

    /**
     * Characters the effects may take in the message. Discord allows 4096 in
     * an embed's description, and the opening paragraph uses some of them.
     */
    private const EFFECTS_CHARS = 3200;

    protected SettingsManagerService $settings;
    protected NotificationService $notifications;

    public function __construct(SettingsManagerService $settings, NotificationService $notifications)
    {
        $this->settings = $settings;
        $this->notifications = $notifications;
    }

    /**
     * Send the message when it is due: true once it went out, false when a
     * send reached nobody, null when there was nothing to send. Never throws,
     * since it runs inside the price refresh.
     */
    public function run(?Carbon $now = null): ?bool
    {
        $now = $now ?? Carbon::now();

        try {
            $report = $this->pending($now);
            $tries = (int) $this->settings->getSettingForCorporation(self::TRIES_KEY, null, 0);
            if (!$report || $tries >= self::TRIES) {
                return null;
            }

            // Asked first, so a run with nobody to tell uses up no try and
            // writes nothing to the notification log.
            if (!$this->notifications->reachesAnyone(NotificationService::TYPE_SETTINGS_MOVED)) {
                return null;
            }

            $result = $this->notifications->sendSettingsMoved($this->message($report));

            if (!empty($result[NotificationService::CHANNEL_DISCORD]['sent'])
                || !empty($result[NotificationService::CHANNEL_SLACK]['success'])) {
                $this->settings->updateGlobalSetting(self::SENT_KEY, $now->toDateTimeString());

                return true;
            }

            $this->settings->updateGlobalSetting(self::TRIES_KEY, $tries + 1, 'integer');

            return false;
        } catch (\Throwable $e) {
            Log::warning('Mining Manager: the message that settings moved into tax profiles could not be sent', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether a move had anything to move: a Moon Owner Corporation, or a
     * corporation with settings of its own. On a new install, or one that
     * never taxed anyone, every setting stayed where it was, so there is
     * nothing to say. The list of configured corporations is written either
     * way, so a count of what was written cannot tell.
     */
    public static function movedAnything(array $report): bool
    {
        return !empty($report['configured']);
    }

    /**
     * The move's report while it still needs saying: settings moved in the
     * last two weeks, there was something to move, and no message has gone
     * out. Null otherwise.
     */
    public function pending(?Carbon $now = null): ?array
    {
        $transfer = new SettingsTransfer();
        if (!$transfer->done() || $this->settings->getSettingForCorporation(self::SENT_KEY, null)) {
            return null;
        }

        $report = $transfer->storedReport();
        if (!$report || empty($report['transferred_at']) || !self::movedAnything($report)) {
            return null;
        }

        $until = Carbon::parse($report['transferred_at'])->addDays(self::DAYS);

        return ($now ?? Carbon::now())->lt($until) ? $report : null;
    }

    /**
     * What the message says, for NotificationService.
     */
    public function message(array $report): array
    {
        $configured = array_map('intval', $report['configured'] ?? []);
        $tickers = DB::table('corporation_infos')->whereIn('corporation_id', $configured)->pluck('ticker', 'corporation_id');
        $effects = $report['effects'] ?? [];

        $lines = [sprintf(
            "Mining Manager moved this install's settings into tax profiles on %s UTC, when the plugin updated. %d values were written where they now live and %d were there already. Every settings row as it was before is kept in the mining_manager_settings_archive table.",
            Carbon::parse($report['transferred_at'])->format('Y-m-d H:i'),
            (int) ($report['written'] ?? 0),
            (int) ($report['kept'] ?? 0)
        ), ''];

        if (!$effects) {
            $lines[] = 'Nothing changed in effect: everyone is taxed as before.';
        } else {
            $lines[] = 'What changed in effect, from the day after:';
            $length = 0;
            foreach ($effects as $i => $effect) {
                $length += mb_strlen($effect) + 3;
                if ($length > self::EFFECTS_CHARS) {
                    $lines[] = sprintf('and %d more in the report', count($effects) - $i);
                    break;
                }
                $lines[] = '• ' . $effect;
            }
        }

        return [
            'description' => implode("\n", $lines),
            'configured' => $configured
                ? implode(', ', array_map(fn (int $id) => isset($tickers[$id]) ? "[{$tickers[$id]}]" : "Corporation {$id}", $configured))
                : 'None, so the Global profile taxes everyone',
            'report_url' => route('mining-manager.settings.index') . '#maintenance',
        ];
    }
}
