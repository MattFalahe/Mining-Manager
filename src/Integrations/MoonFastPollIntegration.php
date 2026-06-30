<?php

namespace MiningManager\Integrations;

use Illuminate\Support\Facades\Log;
use MiningManager\Handlers\MoonNotificationHandler;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * Manager Core ESI fast-poll integration for moon notifications.
 *
 * Mirrors Structure Manager's model: when Manager Core is installed, MM
 * registers a handler with MC's EsiNotificationRegistry so MC's fast-poll
 * (round-robin over the director ESI key pool, ~2 min detection) routes
 * `MoonminingExtractionStarted` straight to us. That's an order of magnitude
 * faster than the SeAT-native path, which has to wait for the corp
 * moon-extraction ESI endpoint to refresh (~30 min cache) before MM even
 * learns a new pull began.
 *
 * **Mutual exclusion** (the "same like Structure Manager does it" bit): when
 * fast-poll is enabled, the SeAT-native `extraction_started` pass in
 * CheckExtractionArrivalsCommand is suppressed so the two paths can't
 * double-fire. When MC is absent (or the operator forces `seat_native`), the
 * cron owns it as before. Whichever path is active, the latch on the
 * extraction row keeps a single notification per extraction.
 *
 * Operator escape hatch: `notifications.moon_extraction_fastpoll_mode`
 *   - 'auto'        (default) — fast-poll when MC present, else SeAT-native
 *   - 'seat_native'           — always use the cron, even with MC installed
 */
class MoonFastPollIntegration
{
    public const MODE_AUTO = 'auto';
    public const MODE_SEAT_NATIVE = 'seat_native';

    public const SETTING_KEY = 'notifications.moon_extraction_fastpoll_mode';

    /**
     * Is MC's notification registry present? (class_exists only — no autoload
     * side effects, safe when MC isn't installed).
     */
    public static function registryAvailable(): bool
    {
        return class_exists('\ManagerCore\Services\ESI\EsiNotificationRegistry');
    }

    /**
     * Operator-chosen mode, validated. Defaults to 'auto'.
     */
    public static function mode(): string
    {
        try {
            $mode = app(SettingsManagerService::class)->getSetting(self::SETTING_KEY, self::MODE_AUTO);
            return in_array($mode, [self::MODE_AUTO, self::MODE_SEAT_NATIVE], true)
                ? $mode
                : self::MODE_AUTO;
        } catch (\Throwable $e) {
            return self::MODE_AUTO;
        }
    }

    /**
     * True when MC fast-poll should own the `extraction_started` notification.
     */
    public static function isFastPollEnabled(): bool
    {
        return self::registryAvailable() && self::mode() === self::MODE_AUTO;
    }

    /**
     * Register MM's moon notification handler with MC's registry. Called
     * unconditionally at ServiceProvider boot (so queue workers register it
     * too — that's the process MC's poll job runs in). No-op when MC is
     * absent or the operator forced SeAT-native.
     */
    public static function registerHandler(): void
    {
        if (!self::isFastPollEnabled()) {
            return;
        }

        try {
            // Resolve via ::class (no leading backslash) — MC binds the
            // singleton under that exact key. A leading-backslash string key
            // would bypass the binding and land our handler on a throwaway
            // instance MC's poll job never sees. (Documented SM gotcha.)
            $registry = app(\ManagerCore\Services\ESI\EsiNotificationRegistry::class);
            $registry->register(
                MoonNotificationHandler::registeredTypes(),
                MoonNotificationHandler::class,
                'mining-manager',
                // queued — the handler POSTs to Discord/Slack; don't block
                // MC's poll job on webhook latency (MC's H7 guidance).
                ['queued' => true]
            );
            Log::info('[Mining Manager] Registered moon notification handler with Manager Core fast-poll');
        } catch (\Throwable $e) {
            Log::warning('[Mining Manager] Could not register moon fast-poll handler: ' . $e->getMessage());
        }
    }
}
