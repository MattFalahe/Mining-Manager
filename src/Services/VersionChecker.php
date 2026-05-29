<?php

namespace MiningManager\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Compares the currently-installed plugin version against the latest
 * stable release on Packagist and reports an update-available status.
 *
 * Designed for the Help & Documentation Overview card: gives operators
 * a low-effort way to see "am I on the latest?" without leaving SeAT.
 *
 * Resilience:
 *   - Result is cached for 6 hours so each visit doesn't hit Packagist
 *   - HTTP request has a 3-second timeout
 *   - Every failure path (network, malformed JSON, missing package data)
 *     swallows the error, logs a warning, and returns 'unknown' status
 *     so the Help page never errors out from a transient Packagist hiccup
 *
 * Standalone: no Manager Core dependency. Works exactly as well on a
 * vanilla SeAT install as on a fully-integrated stack.
 *
 * Pattern source: ported verbatim from Structure Manager
 * (mattfalahe/structure-manager) which itself was ported from SeAT
 * Broadcast (mattfalahe/seat-discord-pings). Same pattern slots cleanly
 * into other plugins by changing the constants here — return shape is
 * stable so Blade renderers can be copied.
 */
class VersionChecker
{
    /** Packagist v2 metadata URL for this package. */
    private const PACKAGIST_URL = 'https://repo.packagist.org/p2/mattfalahe/mining-manager.json';

    /** Composer package key under packages.* in the Packagist response. */
    private const PACKAGE_KEY = 'mattfalahe/mining-manager';

    /** Cache key for the fetched-latest result. */
    private const CACHE_KEY = 'mining-manager:packagist_latest';

    /** Cache TTL (seconds). 6 hours is generous to Packagist + responsive enough. */
    private const CACHE_TTL = 6 * 60 * 60;

    /** Guzzle request timeout (seconds). Help page can't block longer than this. */
    private const HTTP_TIMEOUT = 3;

    /**
     * Return a structured status array for the Help & Documentation
     * Overview card. Always returns a complete shape, even on failure.
     *
     * Shape:
     *   [
     *     'current'      => '2.0.1' | 'dev-dev-5.0' | ...,  // Composer-resolved
     *     'current_source' => 'composer' | 'config',        // where the value came from
     *     'is_dev_branch'  => bool,                         // true for 'dev-*' refs
     *     'latest'       => '2.0.1' | null,                 // latest stable on Packagist
     *     'status'       => 'current'                       // installed == latest
     *                     | 'outdated'                      // installed < latest
     *                     | 'ahead'                         // installed > latest (tagged pre-release)
     *                     | 'dev_branch'                    // installed is a branch ref, no compare
     *                     | 'unknown',                      // could not reach Packagist
     *     'message'      => 'human-readable explanation',
     *     'release_url'  => 'https://github.com/...' | null,
     *   ]
     */
    public function getStatus(): array
    {
        [$current, $source] = $this->resolveInstalledVersion();
        $isDevBranch = $this->looksLikeDevBranch($current);
        $latest = $this->fetchLatestVersion();

        // Dev branch installs (composer require ...:dev-dev-5.0, dev-main, etc.)
        // can't be meaningfully compared with Packagist's tag list — the branch
        // ref may be ahead OR behind any given tag depending on local commit
        // state. Show as 'dev_branch' and leave operators to make their own
        // judgement against the latest-release pill rendered alongside.
        if ($isDevBranch) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => true,
                'latest'         => $latest,
                'status'         => 'dev_branch',
                'message'        => 'You are running a development branch (' . $current . '), not a tagged release. The "Latest release" pill above is the most recent stable Packagist tag — your branch may be ahead of or behind that depending on local commits. Switch to a tagged version (composer require mattfalahe/mining-manager:^X.Y.Z) for a definitive "up to date" comparison.',
                'release_url'    => null,
            ];
        }

        if ($latest === null) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => null,
                'status'         => 'unknown',
                'message'        => 'Unable to check the latest version. Packagist may be unreachable or the network call timed out. The plugin is unaffected — this is informational only.',
                'release_url'    => null,
            ];
        }

        $cmp = version_compare($current, $latest);

        if ($cmp < 0) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => $latest,
                'status'         => 'outdated',
                'message'        => 'A newer release is available. Update via your standard plugin upgrade path (composer + container restart). See the Upgrade section in the CHANGELOG for the exact recipe.',
                'release_url'    => 'https://github.com/MattFalahe/Mining-Manager/releases/tag/' . $latest,
            ];
        }

        if ($cmp > 0) {
            return [
                'current'        => $current,
                'current_source' => $source,
                'is_dev_branch'  => false,
                'latest'         => $latest,
                'status'         => 'ahead',
                'message'        => 'You are running a tagged pre-release newer than the latest stable Packagist release. Common when testing a release-candidate tag before promoting it.',
                'release_url'    => null,
            ];
        }

        return [
            'current'        => $current,
            'current_source' => $source,
            'is_dev_branch'  => false,
            'latest'         => $latest,
            'status'         => 'current',
            'message'        => 'You are running the latest tagged release.',
            'release_url'    => null,
        ];
    }

    /**
     * Ask Composer (Composer 2.0+ runtime metadata API) what version of the
     * plugin package is actually installed. Falls back to the config file
     * value only if Composer metadata is unavailable (unusual — only really
     * happens if the autoloader was bypassed somehow).
     *
     * Returns [string $version, string $source] where $source is one of:
     *   - 'composer' : the canonical answer from Composer's installed.json
     *   - 'config'   : fallback to mining-manager.config.php (may be aspirational)
     *
     * The dual return lets the Help card render a small "(via composer)" /
     * "(via config)" hint so operators know whether the value is trustworthy.
     */
    protected function resolveInstalledVersion(): array
    {
        if (class_exists('\\Composer\\InstalledVersions')) {
            try {
                if (\Composer\InstalledVersions::isInstalled(self::PACKAGE_KEY)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE_KEY);
                    if (is_string($version) && $version !== '') {
                        return [$version, 'composer'];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to config fallback
            }
        }

        return [(string) config('mining-manager.version', '0.0.0'), 'config'];
    }

    /**
     * True if the version string looks like a Composer dev-branch ref
     * (e.g. 'dev-dev-5.0', 'dev-main', 'dev-feature/foo'). These can't
     * be version_compare'd meaningfully against semver tags.
     */
    protected function looksLikeDevBranch(string $version): bool
    {
        return $version === ''
            || str_starts_with($version, 'dev-')
            || str_ends_with($version, '-dev');
    }

    /**
     * Hit Packagist (or cached result) and return the latest stable
     * version string with any "v" prefix stripped. Returns null if the
     * call fails for any reason.
     */
    protected function fetchLatestVersion(): ?string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $client = new Client([
                    'timeout'         => self::HTTP_TIMEOUT,
                    'connect_timeout' => self::HTTP_TIMEOUT,
                    'verify'          => true, // TLS verification on
                    'headers'         => [
                        'Accept'     => 'application/json',
                        'User-Agent' => 'SeAT-MiningManager/' . config('mining-manager.version', 'unknown'),
                    ],
                ]);

                $response = $client->get(self::PACKAGIST_URL);
                $data     = json_decode((string) $response->getBody(), true);

                if (! is_array($data) || ! isset($data['packages'][self::PACKAGE_KEY]) || ! is_array($data['packages'][self::PACKAGE_KEY])) {
                    Log::warning('[MiningManager] VersionChecker: Packagist response missing expected packages.' . self::PACKAGE_KEY . ' shape');
                    return null;
                }

                // Packagist v2 returns versions in descending order. Iterate
                // and grab the first NON-dev version we find — "dev-*" tags
                // are branch installs, not stable releases.
                foreach ($data['packages'][self::PACKAGE_KEY] as $release) {
                    $version = $release['version'] ?? '';
                    if ($version === '' || str_starts_with($version, 'dev-') || str_contains($version, '-dev')) {
                        continue;
                    }
                    // Strip the "v" prefix some packages use (we don't, but be defensive).
                    return ltrim((string) $version, 'v');
                }

                return null;
            } catch (\Throwable $e) {
                Log::warning('[MiningManager] VersionChecker: failed to fetch latest version from Packagist: ' . $e->getMessage());
                return null;
            }
        });
    }
}
