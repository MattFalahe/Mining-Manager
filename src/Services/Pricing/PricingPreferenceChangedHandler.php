<?php

namespace MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Subscriber for Manager Core's `pricing.preference_changed` EventBus topic.
 *
 * Fired by MC's PricingPreferencesController whenever an operator updates or
 * resets a row in the Pricing Preferences admin UI. Mining Manager subscribes
 * (in MiningManagerServiceProvider::registerCrossPluginPricingSubscription)
 * so an operator's MC-side change takes effect immediately, not after the
 * next scheduled cache cycle.
 *
 * Filter: we only care about events whose `plugin_key` payload field equals
 * 'mining-manager'. Other plugins' preference changes are no-ops for us.
 *
 * Action: flush MM's local price cache tagged ['mining-manager', 'prices'].
 * MM's PriceService::getPriceData() reads through this cache, so a flush
 * forces the next read to go fetch fresh prices from MC via the bridge,
 * picking up the operator's new market/price_type.
 *
 * Why not also dispatch a refresh job here:
 *   - The scheduled `mining-manager:cache-prices` cron will refresh on its
 *     next tick anyway. Forcing an immediate refresh would inflate operator
 *     wait time on the save click + double the bridge load if MC was already
 *     about to refresh.
 *   - If the operator wants an immediate refresh, the Settings page already
 *     has the "Refresh prices now" button + the artisan command is
 *     documented in the Manual Price Cache Management card.
 *
 * Failure handling: any exception is caught and logged at warning. Cache
 * driver mismatches (file/database cache backends don't support `tags()`)
 * fall through to a tagless `cache()->flush()` last-resort. Either way the
 * handler is non-fatal — worst case the operator sees stale prices until
 * the next scheduled refresh.
 */
class PricingPreferenceChangedHandler
{
    /**
     * EventBus capability signature.
     *
     * @param string $eventName  e.g. 'pricing.preference_changed'
     * @param string $publisher  e.g. 'manager-core'
     * @param array  $payload    See MC Topics.php for the contract:
     *                           plugin_key, old_market, new_market,
     *                           old_price_type, new_price_type,
     *                           admin_overridden, action
     */
    public function handle(string $eventName, string $publisher, array $payload): void
    {
        // Filter: this handler only acts on Mining Manager's own pref changes.
        // Other plugins (Buyback Manager, etc.) have their own subscribers
        // for their own rows.
        $pluginKey = $payload['plugin_key'] ?? null;
        if ($pluginKey !== 'mining-manager') {
            return;
        }

        $action       = $payload['action']       ?? 'update';
        $oldMarket    = $payload['old_market']    ?? '(null)';
        $newMarket    = $payload['new_market']    ?? '(unknown)';
        $oldPriceType = $payload['old_price_type'] ?? '(null)';
        $newPriceType = $payload['new_price_type'] ?? '(unknown)';

        Log::info(sprintf(
            '[MM] pricing.preference_changed received: action=%s, market %s → %s, price_type %s → %s. Flushing local price cache.',
            $action,
            $oldMarket,
            $newMarket,
            $oldPriceType,
            $newPriceType
        ));

        // Flush MM's price cache so next read fetches fresh values from MC
        // via the bridge. Two-attempt path because Laravel's cache `tags()`
        // method only works on supported drivers (redis, memcached). File
        // and database drivers throw BadMethodCallException.
        try {
            Cache::tags(['mining-manager', 'prices'])->flush();
            Cache::tags(['mining-manager', 'moon-values'])->flush();
            Log::info('[MM] Flushed tagged price caches after pricing.preference_changed.');
        } catch (\Throwable $tagEx) {
            // Cache driver doesn't support tags — log + soldier on. The
            // scheduled cache refresh will eventually surface the new
            // market's prices; we just lose the "immediate" property.
            Log::debug('[MM] Cache driver does not support tags; skipping tag-based flush after pricing.preference_changed: ' . $tagEx->getMessage());
        }
    }
}
