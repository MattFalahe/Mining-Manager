<?php

namespace MiningManager\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\TypeIdRegistry;

/**
 * Sends a small, paced set of price requests to Janice and reports what comes
 * back, so a change to how prices are fetched can be decided on real answers
 * rather than on the API description alone. It saves nothing: no price is
 * cached and no setting changes.
 */
class ProbeJaniceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mining-manager:probe-janice
                            {--check=all : Which checks to run: single, bulk or all}
                            {--single=10 : How many one-price requests to send (at most 50)}
                            {--sizes=10,25,50,100,250,all : Prices per bulk request to try, comma separated; "all" asks for every price in one request}
                            {--pause=2 : Seconds to wait between requests (at least 1)}
                            {--market= : jita or amarr; defaults to the market on the Pricing tab}
                            {--type=all : Which prices to ask for, same choices as cache-prices}
                            {--headers : Print every header of the first response in each check}
                            {--dry-run : Show what would be sent without contacting Janice}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probe the Janice API with paced single and bulk price requests, without saving anything';

    private const PRICER_URL = 'https://janice.e-351.com/api/rest/v2/pricer';

    // Same market ids the price provider sends.
    private const MARKETS = ['jita' => 2, 'amarr' => 1];

    // Janice publishes no rate limit and blocks keys that send too much, so
    // the probe keeps its own total small whatever it is asked to do.
    private const MAX_SINGLE = 50;
    private const MAX_SIZES = 10;
    private const TIMEOUT_SECONDS = 30;

    /**
     * Whether a request has gone out yet, so the pause applies between requests only.
     */
    private bool $sentAny = false;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lock = Cache::lock('mining-manager:probe-janice', 900);
        if (!$lock->get()) {
            $this->warn('Another probe is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            return $this->probe();
        } finally {
            $lock->release();
        }
    }

    private function probe(): int
    {
        $pricing = app(SettingsManagerService::class)->getPricingSettings();
        $apiKey = (string) ($pricing['janice_api_key'] ?? '');

        $marketName = strtolower((string) ($this->option('market') ?: ($pricing['janice_market'] ?? 'jita')));
        if (!isset(self::MARKETS[$marketName])) {
            $this->error("Unknown market '{$marketName}'. Use jita or amarr.");
            return Command::FAILURE;
        }
        $marketId = self::MARKETS[$marketName];

        $check = strtolower((string) $this->option('check'));
        if (!in_array($check, ['single', 'bulk', 'all'], true)) {
            $this->error("Unknown check '{$check}'. Use single, bulk or all.");
            return Command::FAILURE;
        }

        $type = (string) $this->option('type');
        $typeIds = $this->orderedTypeIds($type);
        if (empty($typeIds)) {
            $this->error("No type IDs for --type={$type}.");
            return Command::FAILURE;
        }

        $pause = max(1, (int) $this->option('pause'));
        $singleCount = $check === 'bulk' ? 0 : min(self::MAX_SINGLE, max(0, (int) $this->option('single')));
        $sizes = $check === 'single' ? [] : $this->parseSizes((string) $this->option('sizes'), count($typeIds));
        $requests = $singleCount + count($sizes);

        $this->info('Janice probe');
        $this->line("  Market:        {$marketName} (id {$marketId})");
        $this->line('  Prices:        ' . count($typeIds) . " type IDs from --type={$type}");
        $this->line('  API key:       ' . ($apiKey === '' ? 'not set' : 'set'));
        $this->line("  Pause:         {$pause}s between requests");
        $this->line('  Single check:  ' . ($singleCount > 0 ? "{$singleCount} requests, one price each" : 'not run'));
        $this->line('  Bulk check:    ' . ($sizes ? count($sizes) . ' requests asking for ' . implode(', ', $sizes) . ' prices' : 'not run'));
        $this->line("  Total:         {$requests} requests, about " . max(0, $requests - 1) * $pause . 's of pauses');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was sent.');
            return Command::SUCCESS;
        }

        if ($apiKey === '') {
            $this->error('No Janice API key is set. Add it on the Pricing tab first.');
            return Command::FAILURE;
        }

        if ($requests === 0) {
            $this->warn('Nothing to send.');
            return Command::SUCCESS;
        }

        $stopped = null;
        $singles = [];
        $bulkItems = [];
        $workingSizes = [];

        if ($singleCount > 0) {
            $this->info('Single-price requests (GET /pricer/{type})');
            $rows = [];

            foreach (array_slice($typeIds, 0, $singleCount) as $index => $typeId) {
                $response = $this->send('GET', self::PRICER_URL . '/' . $typeId . '?market=' . $marketId, null, $apiKey, $pause);
                if ($index === 0) {
                    $this->printHeaders($response, 'single');
                }

                $item = $response['status'] === 200 && is_array($response['json']) ? $response['json'] : null;
                if ($item !== null) {
                    $singles[$typeId] = $item;
                }

                $rows[] = [
                    $typeId,
                    $item['itemType']['name'] ?? '-',
                    $response['status'] ?? 'no answer',
                    $response['ms'],
                    $response['bytes'],
                    $this->price($item['immediatePrices']['buyPrice'] ?? null),
                    $this->price($item['immediatePrices']['sellPrice'] ?? null),
                    $this->price($item['immediatePrices']['splitPrice'] ?? null),
                    $item === null ? '-' : (isset($item['effectivePrices']) ? 'yes' : 'no'),
                ];

                $stopped = $this->stopReason($response);
                if ($stopped !== null) {
                    break;
                }
            }

            $this->table(['Type', 'Name', 'Status', 'ms', 'Bytes', 'Buy', 'Sell', 'Split', 'effectivePrices'], $rows);
            $this->newLine();
        }

        if ($stopped === null && !empty($sizes)) {
            $this->info('Bulk requests (POST /pricer, one type ID per line)');
            $rows = [];

            foreach ($sizes as $index => $size) {
                $ids = array_slice($typeIds, 0, $size);
                $response = $this->send('POST', self::PRICER_URL . '?market=' . $marketId, implode("\n", $ids), $apiKey, $pause);
                if ($index === 0) {
                    $this->printHeaders($response, 'bulk');
                }

                $items = $response['status'] === 200 && is_array($response['json']) && array_is_list($response['json'])
                    ? $response['json']
                    : [];

                $returned = [];
                $zero = 0;
                $withEffective = 0;
                foreach ($items as $item) {
                    $eid = (int) ($item['itemType']['eid'] ?? 0);
                    if ($eid > 0) {
                        $returned[$eid] = true;
                        $bulkItems[$eid] ??= $item;
                    }
                    $prices = $item['immediatePrices'] ?? [];
                    if ((float) ($prices['buyPrice'] ?? 0) <= 0 && (float) ($prices['sellPrice'] ?? 0) <= 0) {
                        $zero++;
                    }
                    if (isset($item['effectivePrices'])) {
                        $withEffective++;
                    }
                }
                $missing = array_values(array_diff($ids, array_keys($returned)));

                $answered = $response['status'] === 200;
                if ($answered) {
                    $workingSizes[] = $size;
                }

                $rows[] = [
                    $size,
                    $response['status'] ?? 'no answer',
                    $response['ms'],
                    $response['bytes'],
                    $answered ? count($items) : '-',
                    $answered ? count($missing) : '-',
                    $answered ? $zero : '-',
                    $answered ? $withEffective : '-',
                    $answered ? implode(', ', array_slice($missing, 0, 5)) : (string) $response['error'],
                ];

                $stopped = $this->stopReason($response);
                if ($stopped !== null) {
                    break;
                }
            }

            $this->table(['Prices asked', 'Status', 'ms', 'Bytes', 'Items back', 'Missing', 'Zero price', 'effectivePrices', 'Missing IDs (first 5) or error'], $rows);
            $this->newLine();
        }

        $this->compare($singles, $bulkItems);

        if ($stopped !== null) {
            $this->warn("Stopped: {$stopped}. Nothing more was sent.");
            $this->newLine();
        }

        if (!empty($workingSizes)) {
            $this->info('Requests for a full refresh of ' . count($typeIds) . ' prices');
            $this->line('  One price per request, as the refresh does today: ' . count($typeIds));
            foreach ($workingSizes as $size) {
                $this->line(sprintf('  %d prices per request: %d', $size, (int) ceil(count($typeIds) / $size)));
            }
        }

        return $stopped === null ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Send one request, pausing first unless it is the first of the run.
     */
    private function send(string $method, string $url, ?string $body, string $apiKey, int $pause): array
    {
        if ($this->sentAny) {
            sleep($pause);
        }
        $this->sentAny = true;

        $request = Http::timeout(self::TIMEOUT_SECONDS)->withHeaders([
            'X-ApiKey' => $apiKey,
            'accept' => 'application/json',
        ]);

        $started = microtime(true);

        try {
            $response = $method === 'POST'
                ? $request->withBody((string) $body, 'text/plain')->post($url)
                : $request->get($url);
        } catch (ConnectionException $e) {
            return [
                'status' => null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'bytes' => 0,
                'json' => null,
                'headers' => [],
                'retry_after' => null,
                'error' => $e->getMessage(),
            ];
        }

        $raw = (string) $response->body();

        return [
            'status' => $response->status(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'bytes' => strlen($raw),
            'json' => $response->json(),
            'headers' => $response->headers(),
            'retry_after' => $response->header('Retry-After') ?: null,
            'error' => $response->successful() ? null : substr(trim($raw), 0, 120),
        ];
    }

    /**
     * Why the probe should send nothing more after this answer, or null to carry on.
     */
    private function stopReason(array $response): ?string
    {
        $status = $response['status'];

        if ($status === null) {
            return 'Janice did not answer (' . $response['error'] . ')';
        }
        if ($status === 429) {
            return 'Janice answered 429, too many requests'
                . ($response['retry_after'] ? ", Retry-After {$response['retry_after']}" : '');
        }
        if ($status === 401 || $status === 403) {
            return "Janice refused the API key ({$status})";
        }
        if ($status >= 500) {
            return "Janice returned a server error ({$status})";
        }

        return null;
    }

    private function printHeaders(array $response, string $check): void
    {
        $rows = [];
        foreach ($response['headers'] as $name => $values) {
            if ($this->option('headers') || preg_match('/rate|limit|retry|quota|throttl/i', (string) $name)) {
                $rows[] = [$name, implode(', ', (array) $values)];
            }
        }

        if (!empty($rows)) {
            $this->line("Headers on the first {$check} response:");
            $this->table(['Header', 'Value'], $rows);
        } elseif ($response['status'] !== null) {
            $this->line("No rate limit headers on the first {$check} response.");
        }
    }

    /**
     * Put the same type answered both ways side by side. Janice refreshes its
     * prices on its own schedule, so a difference can be an update between
     * the two requests rather than a disagreement between the endpoints.
     */
    private function compare(array $singles, array $bulkItems): void
    {
        $common = array_intersect_key($singles, $bulkItems);
        if (empty($common)) {
            return;
        }

        $rows = [];
        foreach ($common as $typeId => $single) {
            $bulk = $bulkItems[$typeId];
            $same = true;
            $cells = [$typeId];
            foreach (['buyPrice', 'sellPrice', 'splitPrice'] as $field) {
                $a = (float) ($single['immediatePrices'][$field] ?? 0);
                $b = (float) ($bulk['immediatePrices'][$field] ?? 0);
                $same = $same && abs($a - $b) < 0.005;
                $cells[] = $this->price($a) . ' / ' . $this->price($b);
            }
            $cells[] = $same ? 'yes' : 'no';
            $rows[] = $cells;
        }

        $this->info('Same prices from both requests (single / bulk)');
        $this->table(['Type', 'Buy', 'Sell', 'Split', 'Match'], $rows);
        $this->newLine();
    }

    /**
     * The chosen type IDs in a fixed mixed order, so every batch holds a
     * spread of ore, moon ore, ice, gas and minerals and two runs line up.
     */
    private function orderedTypeIds(string $type): array
    {
        $ids = array_values(array_unique(array_map('intval', TypeIdRegistry::getTypeIdsByCategory($type))));

        mt_srand(351);
        shuffle($ids);
        mt_srand();

        return $ids;
    }

    private function parseSizes(string $option, int $available): array
    {
        $sizes = [];
        foreach (explode(',', $option) as $part) {
            $part = strtolower(trim($part));
            $size = $part === 'all' ? $available : (int) $part;
            if ($size > 0) {
                $sizes[] = min($size, $available);
            }
        }

        return array_slice(array_values(array_unique($sizes)), 0, self::MAX_SIZES);
    }

    private function price($value): string
    {
        return $value === null ? '-' : number_format((float) $value, 2);
    }
}
