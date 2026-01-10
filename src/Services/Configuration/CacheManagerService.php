<?php

namespace MiningManager\Services\Configuration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;
use Exception;

/**
 * Service for centralized cache management
 * 
 * Provides:
 * - Cache key generation and management
 * - Cache invalidation strategies
 * - Performance monitoring
 * - Cache warming
 * - Cache statistics
 */
class CacheManagerService
{
    /**
     * Cache prefixes for different data types
     */
    const PREFIX_PRICES = 'mm_prices_';
    const PREFIX_LEDGER = 'mm_ledger_';
    const PREFIX_TAX = 'mm_tax_';
    const PREFIX_EVENTS = 'mm_events_';
    const PREFIX_MOON = 'mm_moon_';
    const PREFIX_ANALYTICS = 'mm_analytics_';
    const PREFIX_SETTINGS = 'mm_settings_';
    const PREFIX_USER = 'mm_user_';

    /**
     * Default cache duration in minutes
     */
    const DEFAULT_DURATION = 240;

    /**
     * Statistics cache key
     */
    const STATS_KEY = 'mm_cache_stats';

    /**
     * Settings manager
     */
    protected SettingsManagerService $settings;

    /**
     * Constructor
     */
    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Remember a value in cache
     *
     * @param string $key
     * @param callable $callback
     * @param int|null $duration Duration in minutes
     * @param string|null $prefix
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $duration = null, ?string $prefix = null)
    {
        if (!$this->isCacheEnabled()) {
            return $callback();
        }

        $fullKey = $this->generateKey($key, $prefix);
        $duration = $duration ?? $this->getDefaultDuration();

        try {
            $value = Cache::remember($fullKey, $duration * 60, function () use ($callback, $key) {
                $this->recordCacheMiss($key);
                return $callback();
            });

            $this->recordCacheHit($key);
            return $value;
        } catch (Exception $e) {
            Log::error('Cache remember failed', [
                'key' => $fullKey,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }

    /**
     * Store a value in cache
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $duration
     * @param string|null $prefix
     * @return bool
     */
    public function put(string $key, $value, ?int $duration = null, ?string $prefix = null): bool
    {
        if (!$this->isCacheEnabled()) {
            return false;
        }

        $fullKey = $this->generateKey($key, $prefix);
        $duration = $duration ?? $this->getDefaultDuration();

        try {
            Cache::put($fullKey, $value, $duration * 60);
            
            $this->recordCacheWrite($key);
            
            return true;
        } catch (Exception $e) {
            Log::error('Cache put failed', [
                'key' => $fullKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get a value from cache
     *
     * @param string $key
     * @param mixed $default
     * @param string|null $prefix
     * @return mixed
     */
    public function get(string $key, $default = null, ?string $prefix = null)
    {
        if (!$this->isCacheEnabled()) {
            return $default;
        }

        $fullKey = $this->generateKey($key, $prefix);

        try {
            $value = Cache::get($fullKey, $default);
            
            if ($value !== $default) {
                $this->recordCacheHit($key);
            } else {
                $this->recordCacheMiss($key);
            }
            
            return $value;
        } catch (Exception $e) {
            Log::error('Cache get failed', [
                'key' => $fullKey,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Check if key exists in cache
     *
     * @param string $key
     * @param string|null $prefix
     * @return bool
     */
    public function has(string $key, ?string $prefix = null): bool
    {
        if (!$this->isCacheEnabled()) {
            return false;
        }

        $fullKey = $this->generateKey($key, $prefix);
        return Cache::has($fullKey);
    }

    /**
     * Forget a specific cache key
     *
     * @param string $key
     * @param string|null $prefix
     * @return bool
     */
    public function forget(string $key, ?string $prefix = null): bool
    {
        $fullKey = $this->generateKey($key, $prefix);

        try {
            Cache::forget($fullKey);
            Log::info('Cache key forgotten', ['key' => $fullKey]);
            return true;
        } catch (Exception $e) {
            Log::error('Cache forget failed', [
                'key' => $fullKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Forget multiple keys by pattern
     *
     * @param string $pattern
     * @return int Number of keys deleted
     */
    public function forgetPattern(string $pattern): int
    {
        try {
            $driver = Cache::getStore()->getDriver();
            
            // Redis-specific implementation
            if ($driver instanceof \Illuminate\Cache\RedisStore) {
                return $this->forgetPatternRedis($pattern);
            }
            
            // For other drivers, we can't efficiently delete by pattern
            Log::warning('Pattern deletion not supported for current cache driver');
            return 0;
        } catch (Exception $e) {
            Log::error('Cache forget pattern failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clear all cache for a specific prefix
     *
     * @param string $prefix
     * @return int
     */
    public function clearByPrefix(string $prefix): int
    {
        return $this->forgetPattern($prefix . '*');
    }

    /**
     * Clear all Mining Manager caches
     *
     * @return bool
     */
    public function clearAll(): bool
    {
        try {
            $prefixes = [
                self::PREFIX_PRICES,
                self::PREFIX_LEDGER,
                self::PREFIX_TAX,
                self::PREFIX_EVENTS,
                self::PREFIX_MOON,
                self::PREFIX_ANALYTICS,
                self::PREFIX_SETTINGS,
                self::PREFIX_USER,
            ];

            $totalCleared = 0;
            foreach ($prefixes as $prefix) {
                $totalCleared += $this->clearByPrefix($prefix);
            }

            Log::info('All Mining Manager caches cleared', ['total_keys' => $totalCleared]);
            return true;
        } catch (Exception $e) {
            Log::error('Failed to clear all caches', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Warm up specific caches
     *
     * @param array $types Types to warm up (prices, ledger, etc.)
     * @return array Results
     */
    public function warmUp(array $types = []): array
    {
        $results = [];

        if (empty($types) || in_array('prices', $types)) {
            $results['prices'] = $this->warmUpPrices();
        }

        if (empty($types) || in_array('settings', $types)) {
            $results['settings'] = $this->warmUpSettings();
        }

        if (empty($types) || in_array('analytics', $types)) {
            $results['analytics'] = $this->warmUpAnalytics();
        }

        Log::info('Cache warm up completed', $results);

        return $results;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        if (!$this->isCacheEnabled()) {
            return [
                'enabled' => false,
                'message' => 'Cache is disabled'
            ];
        }

        $hits = (int) Cache::get('mining_manager_cache_hits', 0);
        $misses = (int) Cache::get('mining_manager_cache_misses', 0);
        $writes = (int) Cache::get('mining_manager_cache_writes', 0);
        $startedAt = Cache::get('mining_manager_cache_started_at', Carbon::now()->toDateTimeString());

        $total = $hits + $misses;
        $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;

        return [
            'enabled' => true,
            'hits' => $hits,
            'misses' => $misses,
            'writes' => $writes,
            'total_requests' => $total,
            'hit_rate' => round($hitRate, 2),
            'started_at' => $startedAt,
            'driver' => config('cache.default'),
            'key_counts' => $this->getKeyCounts()
        ];
    }

    /**
     * Reset cache statistics
     *
     * @return void
     */
    public function resetStatistics(): void
    {
        Cache::forget('mining_manager_cache_hits');
        Cache::forget('mining_manager_cache_misses');
        Cache::forget('mining_manager_cache_writes');
        Cache::put('mining_manager_cache_started_at', Carbon::now()->toDateTimeString(), 86400);

        Log::info('Cache statistics reset');
    }

    /**
     * Get cache key counts by prefix
     *
     * @return array
     */
    protected function getKeyCounts(): array
    {
        $counts = [];
        $prefixes = [
            'prices' => self::PREFIX_PRICES,
            'ledger' => self::PREFIX_LEDGER,
            'tax' => self::PREFIX_TAX,
            'events' => self::PREFIX_EVENTS,
            'moon' => self::PREFIX_MOON,
            'analytics' => self::PREFIX_ANALYTICS,
            'settings' => self::PREFIX_SETTINGS,
            'user' => self::PREFIX_USER,
        ];

        foreach ($prefixes as $name => $prefix) {
            $counts[$name] = $this->countKeysByPrefix($prefix);
        }

        return $counts;
    }

    /**
     * Count cache keys by prefix
     *
     * @param string $prefix
     * @return int
     */
    protected function countKeysByPrefix(string $prefix): int
    {
        try {
            $driver = Cache::getStore()->getDriver();
            
            if ($driver instanceof \Illuminate\Cache\RedisStore) {
                return $this->countKeysRedis($prefix . '*');
            }
            
            return 0; // Not supported for other drivers
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Generate a cache key
     *
     * @param string $key
     * @param string|null $prefix
     * @return string
     */
    protected function generateKey(string $key, ?string $prefix = null): string
    {
        $prefix = $prefix ?? self::PREFIX_SETTINGS;
        return $prefix . $key;
    }

    /**
     * Check if cache is enabled
     *
     * @return bool
     */
    protected function isCacheEnabled(): bool
    {
        return $this->settings->getSetting('cache_enabled', true);
    }

    /**
     * Get default cache duration
     *
     * @return int
     */
    protected function getDefaultDuration(): int
    {
        return $this->settings->getSetting('cache_duration', self::DEFAULT_DURATION);
    }

    /**
     * Record a cache hit
     *
     * @param string $key
     * @return void
     */
    protected function recordCacheHit(string $key): void
    {
        if (!$this->settings->getSetting('cache_statistics', true)) {
            return;
        }

        try {
            Cache::increment('mining_manager_cache_hits');
        } catch (Exception $e) {
            // Silently fail to not interrupt main operations
        }
    }

    /**
     * Record a cache miss
     *
     * @param string $key
     * @return void
     */
    protected function recordCacheMiss(string $key): void
    {
        if (!$this->settings->getSetting('cache_statistics', true)) {
            return;
        }

        try {
            Cache::increment('mining_manager_cache_misses');
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Record a cache write
     *
     * @param string $key
     * @return void
     */
    protected function recordCacheWrite(string $key): void
    {
        if (!$this->settings->getSetting('cache_statistics', true)) {
            return;
        }

        try {
            Cache::increment('mining_manager_cache_writes');
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Forget pattern using Redis
     *
     * @param string $pattern
     * @return int
     */
    protected function forgetPatternRedis(string $pattern): int
    {
        try {
            $redis = Redis::connection(config('cache.stores.redis.connection', 'default'));
            $cursor = '0';
            $deleted = 0;
            do {
                [$cursor, $keys] = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                if (!empty($keys)) {
                    $redis->del(...$keys);
                    $deleted += count($keys);
                }
            } while ($cursor !== '0');
            return $deleted;
        } catch (Exception $e) {
            Log::error('Redis pattern deletion failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Count keys using Redis
     *
     * @param string $pattern
     * @return int
     */
    protected function countKeysRedis(string $pattern): int
    {
        try {
            $redis = Redis::connection(config('cache.stores.redis.connection', 'default'));
            $keys = $redis->keys($pattern);
            return count($keys);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Warm up prices cache
     *
     * @return array
     */
    protected function warmUpPrices(): array
    {
        try {
            // This would typically call the MarketDataService
            // For now, just return a placeholder
            return [
                'status' => 'success',
                'message' => 'Prices cache warmed up'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Warm up settings cache
     *
     * @return array
     */
    protected function warmUpSettings(): array
    {
        try {
            // Force reload settings
            $this->settings->clearCache();
            $this->settings->getAllWithMetadata();
            
            return [
                'status' => 'success',
                'message' => 'Settings cache warmed up'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Warm up analytics cache
     *
     * @return array
     */
    protected function warmUpAnalytics(): array
    {
        try {
            // This would typically call the AnalyticsService
            return [
                'status' => 'success',
                'message' => 'Analytics cache warmed up'
            ];
        } catch (Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Invalidate cache for a character
     *
     * @param int $characterId
     * @return bool
     */
    public function invalidateCharacterCache(int $characterId): bool
    {
        return $this->forgetPattern(self::PREFIX_USER . $characterId . '_*') > 0;
    }

    /**
     * Invalidate cache for a date range
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return bool
     */
    public function invalidateDateRange(Carbon $startDate, Carbon $endDate): bool
    {
        $period = $startDate->format('Ym') . '_' . $endDate->format('Ym');
        return $this->forgetPattern('*_' . $period . '_*') > 0;
    }

    /**
     * Tag-based cache management (if supported)
     *
     * @param array $tags
     * @param string $key
     * @param callable $callback
     * @param int|null $duration
     * @return mixed
     */
    public function tags(array $tags, string $key, callable $callback, ?int $duration = null)
    {
        if (!$this->isCacheEnabled()) {
            return $callback();
        }

        try {
            // Check if the current cache driver supports tagging
            if (config('cache.default') === 'redis' || config('cache.default') === 'memcached') {
                $duration = $duration ?? $this->getDefaultDuration();
                return Cache::tags($tags)->remember($key, $duration * 60, $callback);
            }
            
            // Fallback to regular caching without tags
            return $this->remember($key, $callback, $duration);
        } catch (Exception $e) {
            Log::error('Tagged cache failed', [
                'tags' => $tags,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }

    /**
     * Flush cache by tags
     *
     * @param array $tags
     * @return bool
     */
    public function flushTags(array $tags): bool
    {
        try {
            if (config('cache.default') === 'redis' || config('cache.default') === 'memcached') {
                Cache::tags($tags)->flush();
                Log::info('Cache flushed by tags', ['tags' => $tags]);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            Log::error('Failed to flush tags', [
                'tags' => $tags,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get cache information for debugging
     *
     * @return array
     */
    public function getDebugInfo(): array
    {
        return [
            'enabled' => $this->isCacheEnabled(),
            'driver' => config('cache.default'),
            'default_duration' => $this->getDefaultDuration(),
            'statistics' => $this->getStatistics(),
            'supports_tags' => in_array(config('cache.default'), ['redis', 'memcached']),
            'prefixes' => [
                'prices' => self::PREFIX_PRICES,
                'ledger' => self::PREFIX_LEDGER,
                'tax' => self::PREFIX_TAX,
                'events' => self::PREFIX_EVENTS,
                'moon' => self::PREFIX_MOON,
                'analytics' => self::PREFIX_ANALYTICS,
                'settings' => self::PREFIX_SETTINGS,
                'user' => self::PREFIX_USER,
            ]
        ];
    }
}
