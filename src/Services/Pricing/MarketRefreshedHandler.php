<?php

namespace MiningManager\Services\Pricing;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use MiningManager\Services\Configuration\SettingsManagerService;

/**
 * Subscriber for Manager Core's `pricing.market_refreshed` EventBus topic.
 *
 * Manager Core publishes it when it has finished fetching prices for a
 * market. When that market is the one Mining Manager prices at, the prices
 * are copied into mining_price_cache straight away, so they are as fresh as
 * Manager Core's and a market change arrives minutes after the operator
 * saved it. A Manager Core that never publishes the topic changes nothing:
 * the scheduled `mining-manager:cache-prices` does the same copy.
 *
 * Subscribed queued, so this runs on a worker and never inside Manager
 * Core's own refresh.
 */
class MarketRefreshedHandler
{
    /**
     * EventBus capability signature.
     *
     * @param array $payload market, and optionally plugins: the plugins
     *                       subscribed to anything that was refreshed
     */
    public function handle(string $eventName, string $publisher, array $payload): void
    {
        $settings = app(SettingsManagerService::class);

        // A worker lives for hours and this may be the first it hears of a
        // market change.
        $settings->forgetMcPreference();
        $pricing = $settings->getPricingSettings();

        if (($pricing['price_provider'] ?? null) !== PriceProviderService::PROVIDER_MANAGER_CORE) {
            return;
        }

        if (($payload['market'] ?? null) !== ($pricing['manager_core_market'] ?? null)) {
            return;
        }

        // A refresh of a few types another plugin just subscribed is not ours.
        if (isset($payload['plugins']) && is_array($payload['plugins']) && !in_array('mining-manager', $payload['plugins'], true)) {
            return;
        }

        try {
            // Copy only. If Manager Core cannot be copied from, the scheduled
            // run is the one that goes to the fallback provider, not this.
            Artisan::call('mining-manager:cache-prices', ['--manager-core-only' => true]);

            Log::info("[MM] Manager Core refreshed {$payload['market']}, prices copied.");
        } catch (\Throwable $e) {
            Log::warning('[MM] Could not copy prices after Manager Core refreshed its market: ' . $e->getMessage());
        }
    }
}
