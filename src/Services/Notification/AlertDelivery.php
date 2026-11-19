<?php

namespace MiningManager\Services\Notification;

use Illuminate\Support\Facades\Cache;
use MiningManager\Handlers\MoonNotificationHandler;

/**
 * Whether a scheduled alert has been said, for the alerts that are sent once
 * and then marked as sent.
 *
 * One that reached a webhook or Slack is done. So is one nobody is set up to
 * hear, with its type switched off or no webhook bound to it: there is nobody
 * to wait for. One every channel refused is tried again, a little later each
 * time. One whose only webhooks are switched off waits until one is switched
 * back on, or until the alert no longer applies, which each caller decides.
 */
class AlertDelivery
{
    public const DELIVERED = 'delivered';
    public const NOBODY = 'nobody';
    public const REFUSED = 'refused';
    public const HELD = 'held';
    public const RETRYING = 'retrying';

    /**
     * Minutes before trying a refused alert again: after the first refusal,
     * the second, the third, and every one after that.
     */
    protected const RETRY_MINUTES = [5, 15, 30, 60];

    /**
     * A held alert looks at its webhooks again this often rather than every
     * minute: the look reads the webhook table and logs what it found.
     */
    protected const HELD_RECHECK_MINUTES = 5;

    protected const CACHE_PREFIX = 'mining-manager:alert-delivery:';

    /** Longer than any alert stays worth sending. */
    protected const CACHE_DAYS = 5;

    protected NotificationService $notifications;

    public function __construct(NotificationService $notifications)
    {
        $this->notifications = $notifications;
    }

    /**
     * The key one alert is known by: its type and what it is about.
     */
    public static function key(string $type, int|string $subject): string
    {
        return $type . ':' . $subject;
    }

    /**
     * Why not to send now, or null to go ahead: HELD while the webhooks bound
     * to the alert are switched off, RETRYING while it waits after a refusal.
     *
     * @param string $key from key()
     */
    public function wait(string $type, string $key): ?string
    {
        if ($waiting = $this->waiting($key)) {
            return $waiting;
        }

        $state = Cache::get(self::CACHE_PREFIX . $key);

        if ($this->notifications->heldFor($type)) {
            Cache::put(self::CACHE_PREFIX . $key, [
                'attempts' => (int) ($state['attempts'] ?? 0),
                'next_at' => time() + self::HELD_RECHECK_MINUTES * 60,
                'held' => true,
            ], now()->addDays(self::CACHE_DAYS));

            return self::HELD;
        }

        return null;
    }

    /**
     * What wait() last decided, while it still stands: HELD or RETRYING, or
     * null. Reads the cache alone, for a caller with work to skip first.
     */
    public function waiting(string $key): ?string
    {
        $state = Cache::get(self::CACHE_PREFIX . $key);

        if (is_array($state) && (int) ($state['next_at'] ?? 0) > time()) {
            return ($state['held'] ?? false) ? self::HELD : self::RETRYING;
        }

        return null;
    }

    /**
     * What a send() result means for the alert: DELIVERED, REFUSED or NOBODY.
     */
    public function outcome(array $result): string
    {
        if (!empty($result[NotificationService::CHANNEL_DISCORD]['sent'])
            || !empty($result[NotificationService::CHANNEL_SLACK]['success'])) {
            return self::DELIVERED;
        }

        return MoonNotificationHandler::refused($result) ? self::REFUSED : self::NOBODY;
    }

    /**
     * Note how an attempt went. A refused alert waits longer each time; any
     * other outcome ends the alert's waiting.
     */
    public function record(string $key, string $outcome): void
    {
        if ($outcome !== self::REFUSED) {
            Cache::forget(self::CACHE_PREFIX . $key);

            return;
        }

        $state = Cache::get(self::CACHE_PREFIX . $key);
        $attempts = (is_array($state) ? (int) ($state['attempts'] ?? 0) : 0) + 1;
        $minutes = self::RETRY_MINUTES[min($attempts, count(self::RETRY_MINUTES)) - 1];

        Cache::put(self::CACHE_PREFIX . $key, [
            'attempts' => $attempts,
            'next_at' => time() + $minutes * 60,
            'held' => false,
        ], now()->addDays(self::CACHE_DAYS));
    }
}
