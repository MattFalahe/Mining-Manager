<?php

namespace MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * Subscriber for Manager Core's `pricing.preference_changed` EventBus topic.
 *
 * Fired by MC's PricingPreferencesController whenever an operator updates or
 * resets a row in the Pricing Preferences admin UI. It runs inside that save,
 * so it only does what is quick: it points Manager Core at the new market.
 *
 * Mining Manager's prices live in mining_price_cache, and they can only move
 * once Manager Core holds prices for the new market. So on a market change
 * this subscribes Mining Manager's types there with an immediate refresh,
 * which has Manager Core fetch them on its queue, and drops them from the old
 * market. The copy into mining_price_cache follows when Manager Core says the
 * market is refreshed (MarketRefreshedHandler), or at the next scheduled
 * `mining-manager:cache-prices` on a Manager Core that does not say so.
 *
 * The price type on that page is not read: Buy, Sell or Average is Mining
 * Manager's own setting.
 *
 * Failure handling: nothing here may break the operator's save, so every
 * failure is logged and left to the scheduled refresh.
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
        // Other plugins' rows are theirs to follow.
        if (($payload['plugin_key'] ?? null) !== 'mining-manager') {
            return;
        }

        $settings = app(SettingsManagerService::class);

        // The market was read once already while this request booted, before
        // the save. Anything pricing from here on has to ask again.
        $settings->forgetMcMarket();

        $provider = $settings->getPricingSettings()['price_provider'] ?? null;
        if ($provider !== PriceProviderService::PROVIDER_MANAGER_CORE) {
            Log::info('[MM] pricing.preference_changed received, but prices do not come from Manager Core. Nothing to do.');

            return;
        }

        $oldMarket = (string) ($payload['old_market'] ?? '');
        $newMarket = (string) ($payload['new_market'] ?? '');

        if ($newMarket === '' || $newMarket === $oldMarket) {
            Log::info('[MM] pricing.preference_changed received with the market unchanged. Prices follow at the next refresh.');

            return;
        }

        try {
            $count = app(PriceProviderService::class)->subscribeToManagerCore($newMarket, true);

            // The boot-time check compares against this, and it describes the
            // old market now.
            Cache::forget('mining-manager:mc-subscription-signature');

            Log::info("[MM] Market changed in Manager Core, {$oldMarket} to {$newMarket}: {$count} types subscribed there and a refresh asked for. Prices follow once Manager Core has them.");
        } catch (\Throwable $e) {
            Log::warning('[MM] Could not subscribe to the new Manager Core market, the scheduled refresh will: ' . $e->getMessage());

            return;
        }

        // Left subscribed, Manager Core would keep fetching the old market
        // for nobody.
        if ($oldMarket !== '') {
            try {
                app(\ManagerCore\Services\PluginBridge::class)
                    ->call('ManagerCore', 'pricing.unsubscribeTypes', 'mining-manager', $oldMarket);
            } catch (\Throwable $e) {
                Log::debug('[MM] Could not drop the old Manager Core market subscriptions: ' . $e->getMessage());
            }
        }
    }
}
