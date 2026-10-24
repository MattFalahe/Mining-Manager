<?php

namespace MiningManager\Services\Diagnostic;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MiningManager\Services\Configuration\SettingsManagerService;
use MiningManager\Services\Configuration\SettingsMovedNotice;
use MiningManager\Services\Configuration\SettingsRegistry;
use MiningManager\Services\Configuration\SettingsTransfer;
use MiningManager\Services\Notification\NotificationService;

/**
 * What the Settings Health tab shows: who each tax profile taxes, what every
 * profile holds, and each stored row checked against the place the plugin
 * reads it from.
 *
 * Read-only. Values are read through the settings service, as the rest of
 * the plugin reads them, but never through code that acts on what it reads:
 * billing switches a profile's period over when it reads a queued change that
 * has come due, so periods are read here as stored.
 *
 * Checks are ['label', 'status' => ok|warn|fail|info, 'message'], grouped
 * under a title and a description.
 */
class SettingsHealth
{
    private const TABLE = 'mining_manager_settings';
    private const ARCHIVE = 'mining_manager_settings_archive';
    private const CLEANUP = '2026_01_01_000039_remove_mining_manager_retired_settings';

    private const RARITIES = ['r4' => 'R4', 'r8' => 'R8', 'r16' => 'R16', 'r32' => 'R32', 'r64' => 'R64'];
    private const OTHERS = ['ore' => 'Ore', 'ice' => 'Ice', 'gas' => 'Gas', 'abyssal_ore' => 'Abyssal ore', 'triglavian_ore' => 'Triglavian ore'];
    private const MOON_CHOICES = ['all' => 'all moon ore', 'ours' => 'only at your refineries', 'none' => 'not taxed'];
    private const MOON_SELECTORS = ['all' => 'tax_selector.all_moon_ore', 'ours' => 'tax_selector.only_corp_moon_ore', 'none' => 'tax_selector.no_moon_ore'];
    private const PERIODS = ['monthly' => 'monthly', 'biweekly' => 'twice a month'];
    private const PERIOD_KEY = 'tax_rates.tax_calculation_period';
    private const QUEUE_KEYS = ['tax_rates.tax_calculation_period_pending', 'tax_rates.tax_calculation_period_effective_from'];
    private const PREFIX_KEY = 'tax_rates.tax_code_prefix';

    /** Places named in one message before the rest are only counted. */
    private const NAMED = 12;

    protected SettingsManagerService $settings;

    /** Every settings row, oldest first. */
    private Collection $rows;

    /** @var array<string, array<string|int, object>> key => ['global' | corporation_id => the row the plugin reads there] */
    private array $placed = [];

    /** corporation_id => name and ticker, for every corporation the report mentions. */
    private Collection $corporations;

    /** @var array<string, array> 'global' | corporation_id => what that profile holds */
    private array $profiles = [];

    public function __construct(SettingsManagerService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Everything the tab shows.
     */
    public function report(): array
    {
        $this->rows = DB::table(self::TABLE)->orderBy('id')->get(['id', 'key', 'value', 'type', 'corporation_id', 'updated_at']);
        $this->profiles = [];
        $this->placed = [];

        // The setting model reads the first row it finds, which is the oldest.
        foreach ($this->rows as $row) {
            $this->placed[$row->key][$row->corporation_id === null ? 'global' : (int) $row->corporation_id] ??= $row;
        }

        $moved = $this->settings->usesTaxProfiles();
        $moonOwner = (int) $this->settings->getSettingForCorporation('general.moon_owner_corporation_id', null) ?: null;
        $configured = $moved ? $this->settings->getHomeCorporationIds() : [];

        $mentioned = array_merge($configured, array_filter([$moonOwner]), $this->rows->pluck('corporation_id')->filter()->all());
        $this->corporations = DB::table('corporation_infos')
            ->whereIn('corporation_id', array_unique(array_map('intval', $mentioned)))
            ->get(['corporation_id', 'name', 'ticker'])
            ->keyBy('corporation_id');

        // The Moon Owner first, then by name, as on the Tax profiles page.
        usort($configured, fn (int $a, int $b) => ($a === $moonOwner ? 0 : 1) <=> ($b === $moonOwner ? 0 : 1)
            ?: strcasecmp($this->corporations[$a]->name ?? '', $this->corporations[$b]->name ?? ''));

        $groups = [[
            'title' => 'Tax profiles',
            'icon' => 'fa-users',
            'description' => 'Which corporations are members here, and which profile taxes whom. The Global profile taxes everyone outside the configured corporations.',
            'checks' => $moved ? $this->membership($moonOwner, $configured) : [$this->notMoved()],
        ]];

        if ($moved) {
            $groups[] = [
                'title' => 'Profile values',
                'icon' => 'fa-percent',
                'description' => "Each profile's rates, moon ore choice, billing period and payment code prefix, checked for values that cannot work as meant.",
                'checks' => $this->profileValues($configured),
            ];
        }

        $groups[] = [
            'title' => 'Where values are stored',
            'icon' => 'fa-database',
            'description' => 'Every row in the settings table, checked against the place the plugin reads it from.',
            'checks' => $this->storage($moved, $configured),
        ];

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($groups as $group) {
            foreach ($group['checks'] as $check) {
                $counts[$check['status']]++;
            }
        }

        return [
            'moved' => $moved,
            'overall' => $counts['fail'] ? 'fail' : ($counts['warn'] ? 'warn' : 'ok'),
            'counts' => $counts,
            'groups' => $groups,
            'profiles' => $moved ? $this->profileTable($moonOwner, $configured) : null,
            'install' => $this->installTable(),
            'records' => $this->recordTable(),
            'report_url' => route('mining-manager.settings.index') . '#maintenance',
        ];
    }

    // -----------------------------------------------------------------
    // Tax profiles: who is a member, and who is taxed by what
    // -----------------------------------------------------------------

    private function notMoved(): array
    {
        return $this->check(
            'Settings in tax profiles',
            'fail',
            'Settings are still laid out as before tax profiles, so migration 000038 has not run, or stopped part way. Until it runs the plugin reads them the old way and the Tax profiles page cannot save. The log from the container\'s last start says why the migration stopped.'
        );
    }

    private function membership(?int $moonOwner, array $configured): array
    {
        [$inSeat, $fromMining] = $this->characters($configured);
        $known = fn (int $id) => sprintf(
            '%d characters known to SeAT%s',
            $inSeat[$id] ?? 0,
            !empty($fromMining[$id]) ? " and {$fromMining[$id]} more known from mining" : ''
        );

        $checks = [$this->moved()];

        if ($message = $this->movedMessage()) {
            $checks[] = $message;
        }

        if (!$moonOwner) {
            $checks[] = $this->check('Moon Owner', 'warn', 'No Moon Owner corporation is set. Everything that works for the Moon Owner, from its moons and the Moon Planner to theft detection, has no corporation to work for, and pilots whose corporation is not known yet are taxed by the Global profile. Set it under Settings, Setup.');
        } elseif (!isset($this->corporations[$moonOwner])) {
            $checks[] = $this->check('Moon Owner', 'warn', "Corporation {$moonOwner} is set, but SeAT has no record of it, so its refineries, wallet and name cannot be read. Check it under Settings, Setup.");
        } else {
            $checks[] = $this->check('Moon Owner', 'ok', $this->label($moonOwner) . ', ' . $known($moonOwner) . '. Its members are always members here, and a pilot whose corporation is not known yet is taxed as one of them until the plugin learns it.');
        }

        foreach ($configured as $id) {
            if ($id === $moonOwner) {
                continue;
            }

            if (!isset($this->corporations[$id])) {
                $checks[] = $this->check($this->label($id), 'warn', 'Configured, but SeAT has no record of this corporation, so nothing can show its name. Its members are still taxed by its own profile. If it was added by mistake, take it off under Settings, Setup.');
            } elseif (empty($inSeat[$id]) && empty($fromMining[$id])) {
                $checks[] = $this->check($this->label($id), 'info', 'Configured, but no character in it is known, to SeAT or from mining, so its profile taxes nobody yet.');
            } else {
                $checks[] = $this->check($this->label($id), 'ok', 'Configured, ' . $known($id) . '. Its members are taxed by its own profile.');
            }
        }

        $checks[] = $this->nonMembers($configured);

        return $checks;
    }

    private function moved(): array
    {
        $report = (new SettingsTransfer())->storedReport();
        $at = $report['transferred_at'] ?? $this->settings->getSettingForCorporation(SettingsTransfer::DONE_KEY, null);
        $from = $this->settings->profilesTaxFrom();
        $valuation = $this->settings->getSettingForCorporation(SettingsTransfer::VALUATION_KEY, null);

        $message = "Moved on {$at} UTC";
        if ($report && !SettingsMovedNotice::movedAnything($report)) {
            $message = "In tax profiles since {$at} UTC. Nothing needed moving, as no Moon Owner Corporation was set and no corporation had settings of its own then";
        } elseif ($report) {
            $message .= sprintf(', %d values written where they now live and %d already there', $report['written'] ?? 0, $report['kept'] ?? 0);
        }
        $message .= '.';

        if ($from) {
            $message .= " Mining from {$from->toDateString()} on is taxed by the profiles. Mining from before keeps the rules it was mined under";
            $message .= $valuation ? ', and stays valued at its own market price rather than by the minerals it refines into.' : '.';
        }

        return $this->check('Settings in tax profiles', 'ok', $message . ' The full report is under Settings, Maintenance.');
    }

    /**
     * Where the message announcing the move stands. Null when nothing moved,
     * as on a new install, so there was nothing to announce.
     */
    private function movedMessage(): ?array
    {
        $label = 'Message about the move';
        $sentAt = $this->settings->getSettingForCorporation(SettingsMovedNotice::SENT_KEY, null);
        if ($sentAt) {
            return $this->check($label, 'ok', "Sent to the webhooks that take Plugin Health alerts on {$sentAt} UTC.");
        }

        $report = (new SettingsTransfer())->storedReport();
        if (!$report || empty($report['transferred_at']) || !SettingsMovedNotice::movedAnything($report)) {
            return null;
        }

        $until = Carbon::parse($report['transferred_at'])->addDays(SettingsMovedNotice::DAYS)->toDateString();
        $tries = (int) $this->settings->getSettingForCorporation(SettingsMovedNotice::TRIES_KEY, null, 0);

        if (!app(SettingsMovedNotice::class)->pending()) {
            return $this->check($label, 'info', sprintf('Never sent. It is only news for %d days after the move, and no webhook that takes Plugin Health alerts took it in that time.', SettingsMovedNotice::DAYS));
        }

        if ($tries >= SettingsMovedNotice::TRIES) {
            return $this->check($label, 'warn', "Not sent: {$tries} tries reached nobody, so the plugin has stopped trying. Admins see it on the settings page and the director dashboard until {$until}.");
        }

        if (!app(NotificationService::class)->reachesAnyone(NotificationService::TYPE_SETTINGS_MOVED)) {
            return $this->check($label, 'warn', "Waiting, but no webhook takes Plugin Health alerts, so it cannot go out. Admins see it on the settings page and the director dashboard until {$until}. Tick a Plugin Health alert for a webhook under Settings, Webhooks to send it.");
        }

        return $this->check($label, 'info', 'Waiting for the next price refresh, which sends it to the webhooks that take Plugin Health alerts' . ($tries ? ", after {$tries} tries reached nobody." : '.'));
    }

    /**
     * What the Global profile means for non-members, as the Tax profiles
     * page puts it.
     */
    private function nonMembers(array $configured): array
    {
        if (!$configured) {
            return $this->check('Non-members', 'info', 'No corporation is configured, so the Global profile taxes everyone, for all of their mining.');
        }

        $global = $this->profile(null);
        $rates = [];
        foreach (self::RARITIES as $rarity => $name) {
            $rates[$rarity] = (float) ($global['rates']['moon_ore'][$rarity] ?? 0);
        }

        $free = array_keys(array_filter($rates, fn (float $rate) => $rate <= 0));
        $full = array_keys(array_filter($rates, fn (float $rate) => $rate >= 100));
        $names = fn (array $keys) => implode(', ', array_map(fn (string $key) => self::RARITIES[$key], $keys));
        $overdueDays = (int) ($this->settings->getFeatureFlags()['theft_overdue_days'] ?? 15);
        $theft = "A bill unpaid {$overdueDays} days past its due date counts as theft.";

        if ($global['moon'] === 'none' || count($free) === count($rates)) {
            return $this->check('Non-members', 'warn', 'Non-members mine your moons for free, so theft detection cannot flag anyone: they never owe anything. Set the Global profile\'s moon rates under Settings, Tax profiles.');
        }

        if (count($full) === count($rates)) {
            return $this->check('Non-members', 'info', 'Only configured corporations may mine your moons. Non-members are billed 100% and flagged as theft as soon as their mining is imported, and paying the bill closes the incident.');
        }

        if (count($free) + count($full) === count($rates)) {
            return $this->check('Non-members', 'info', "Non-members mine {$names($free)} for free and pay full price for {$names($full)}. {$theft}");
        }

        return $this->check('Non-members', 'info', 'Non-members are billed ' . $this->rateList($rates) . " for mining at your refineries only. {$theft}");
    }

    /**
     * Characters per corporation: those SeAT knows, and those only known
     * because the plugin looked them up after seeing them mine.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function characters(array $corporationIds): array
    {
        if (!$corporationIds) {
            return [[], []];
        }

        $inSeat = DB::table('character_affiliations')
            ->whereIn('corporation_id', $corporationIds)
            ->selectRaw('corporation_id, COUNT(*) AS characters')
            ->groupBy('corporation_id')
            ->pluck('characters', 'corporation_id')
            ->all();

        $fromMining = !Schema::hasTable('mining_manager_character_affiliations') ? [] : DB::table('mining_manager_character_affiliations as looked_up')
            ->whereIn('looked_up.corporation_id', $corporationIds)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('character_affiliations')
                ->whereColumn('character_affiliations.character_id', 'looked_up.character_id'))
            ->selectRaw('looked_up.corporation_id AS corporation_id, COUNT(*) AS characters')
            ->groupBy('looked_up.corporation_id')
            ->pluck('characters', 'corporation_id')
            ->all();

        return [$inSeat, $fromMining];
    }

    // -----------------------------------------------------------------
    // Profile values
    // -----------------------------------------------------------------

    private function profileValues(array $configured): array
    {
        $profiles = array_merge([null], $configured);

        return [
            $this->rates($profiles, $configured),
            $this->moonChoices($profiles),
            $this->periods($profiles),
            $this->prefixes($profiles),
            $this->unsaved($profiles, $configured),
        ];
    }

    private function rates(array $profiles, array $configured): array
    {
        $outside = [];
        foreach ($profiles as $profile) {
            $values = $this->profile($profile);
            $rates = [];
            foreach (self::RARITIES as $rarity => $name) {
                $rates[$name] = $values['rates']['moon_ore'][$rarity] ?? 0;
            }
            if (!$this->othersUnused($profile, $configured)) {
                foreach (self::OTHERS as $key => $name) {
                    $rates[$name] = $values['rates'][$key] ?? 0;
                }
            }

            foreach ($rates as $name => $rate) {
                if ((float) $rate < 0 || (float) $rate > 100) {
                    $outside[] = $this->profileName($profile) . " {$name} " . $this->percent($rate);
                }
            }
        }

        return $outside
            ? $this->check('Tax rates', 'warn', 'Outside 0% to 100%: ' . $this->named($outside) . '. A rate above 100% bills more than the ore was worth. Correct it under Settings, Tax profiles.')
            : $this->check('Tax rates', 'ok', 'Every rate in every profile is between 0% and 100%.');
    }

    private function moonChoices(array $profiles): array
    {
        $mixed = [];
        foreach ($profiles as $profile) {
            $values = $this->profile($profile);
            $on = array_keys(array_filter([
                'all' => !empty($values['selector']['all_moon_ore']),
                'ours' => !empty($values['selector']['only_corp_moon_ore']),
                'none' => !empty($values['selector']['no_moon_ore']),
            ]));

            if (count($on) !== 1) {
                $mixed[] = sprintf(
                    '%s has %s, read as %s',
                    $this->profileName($profile),
                    $on ? implode(' and ', array_map(fn (string $choice) => self::MOON_CHOICES[$choice], $on)) : 'none of the three',
                    self::MOON_CHOICES[$values['moon']]
                );
            }
        }

        return $mixed
            ? $this->check('Moon ore choice', 'warn', $this->named($mixed) . '. The Tax profiles page saves one choice, so saving it again settles this.')
            : $this->check('Moon ore choice', 'ok', 'Every profile has one moon ore choice.');
    }

    private function periods(array $profiles): array
    {
        $status = 'ok';
        $lines = [];

        foreach ($profiles as $profile) {
            $values = $this->profile($profile);
            $period = $values['period'];
            $line = $this->profileName($profile) . ' ' . (self::PERIODS[$period] ?? $period);

            if ($period === 'weekly') {
                $status = 'warn';
                $line .= ', no longer offered and read as monthly';
            } elseif (!isset(self::PERIODS[$period])) {
                $status = 'warn';
                $line .= ', which is not a period the plugin knows';
            }

            if ($queued = $this->queued($values)) {
                [$to, $from] = $queued;
                $date = $this->date($from);
                $to = self::PERIODS[$to] ?? $to;

                if (!$date) {
                    $status = 'warn';
                    $line .= ", with a change to {$to} queued for a date that cannot be read, so it never happens";
                } elseif ($date->isFuture()) {
                    $line .= ", changing to {$to} on {$date->toDateString()}";
                } else {
                    $line .= ", changing to {$to} since {$date->toDateString()}, which billing applies on its next run";
                }
            } elseif ((bool) $values['pending'] !== (bool) $values['pending_from']) {
                $line .= ', with half of a queued change stored, which is ignored';
            }

            $lines[] = $line;
        }

        return $this->check('Billing periods', $status === 'ok' ? 'info' : $status, implode('. ', $lines) . '.');
    }

    /**
     * Codes are read from the same transfer reason as the payment keywords,
     * so the rules the Tax profiles and Payments pages save by apply here.
     */
    private function prefixes(array $profiles): array
    {
        $payments = $this->settings->getPaymentSettings();
        $keywords = array_filter([
            'upfront payment keyword' => trim((string) ($payments['upfront_keyword'] ?? '')),
            // An empty refund keyword is matched as the default.
            'refund keyword' => trim((string) ($payments['refund_keyword'] ?? '')) ?: 'MM-REFUND',
        ]);

        $shown = [];
        $problems = [];
        foreach ($profiles as $profile) {
            $prefix = $this->profile($profile)['prefix'];
            $name = $this->profileName($profile);
            $shown[] = "{$name} {$prefix}";

            if ($prefix === '') {
                $problems[] = "{$name} has an empty prefix, which the Tax profiles page does not allow";
                continue;
            }

            foreach ($keywords as $which => $keyword) {
                if (stripos($prefix, $keyword) !== false || stripos($keyword, $prefix) !== false) {
                    $problems[] = "{$name}'s prefix {$prefix} overlaps the {$which} {$keyword}, so a payment could read as either";
                }
            }
        }

        if ($problems) {
            return $this->check('Payment code prefixes', 'warn', $this->named($problems) . '. Pick something clearly different under Settings, Tax profiles.');
        }

        $against = implode(' or the ', array_map(fn (string $which, string $keyword) => "{$which} {$keyword}", array_keys($keywords), $keywords));

        return $this->check('Payment code prefixes', 'ok', implode(', ', $shown) . ". None overlaps the {$against}.");
    }

    /**
     * Values a profile has never saved. A profile never falls back to
     * Global, so each of these reads the plugin's built-in default.
     */
    private function unsaved(array $profiles, array $configured): array
    {
        $lines = [];
        foreach ($profiles as $profile) {
            $missing = [];
            foreach (SettingsRegistry::keys(SettingsRegistry::PROFILE) as $key) {
                // No queued change is stored as no rows at all.
                if (in_array($key, self::QUEUE_KEYS, true) || ($this->othersUnused($profile, $configured) && $this->isOtherOre($key))) {
                    continue;
                }

                if (!$this->rowAt($key, $profile)) {
                    $missing[$this->keyLabel($key)] = true;
                }
            }

            if ($missing) {
                $lines[] = $this->profileName($profile) . ': ' . implode(', ', array_keys($missing));
            }
        }

        return $lines
            ? $this->check('Values never saved', 'info', 'Read from the built-in defaults until saved under Settings, Tax profiles. ' . implode('. ', $lines) . '.')
            : $this->check('Values never saved', 'ok', 'Every profile has each of its values saved.');
    }

    // -----------------------------------------------------------------
    // Where values are stored
    // -----------------------------------------------------------------

    private function storage(bool $moved, array $configured): array
    {
        $unknown = [];
        $installElsewhere = [];
        $recordsElsewhere = [];
        $retired = [];
        $leftOver = [];
        $places = [];
        $unreadable = [];

        foreach ($this->rows as $row) {
            $corporation = $row->corporation_id === null ? null : (int) $row->corporation_id;
            $scope = SettingsRegistry::scope($row->key);
            $where = $row->key . ($corporation === null ? '' : ' under ' . $this->label($corporation));

            $places[$row->key . '|' . ($corporation ?? 'global')][] = $where;

            if ($problem = $this->unreadable($row)) {
                $unreadable[] = "{$where} ({$problem})";
            }

            if ($scope === null) {
                $unknown[] = $where;
            } elseif (!$moved) {
                // Before the move every key could have a copy per corporation.
                continue;
            } elseif ($scope === SettingsRegistry::LEGACY) {
                $retired[] = $where;
            } elseif ($corporation !== null && $scope === SettingsRegistry::INSTALL) {
                $installElsewhere[] = $where;
            } elseif ($corporation !== null && $scope === SettingsRegistry::STATE) {
                $recordsElsewhere[] = $where;
            } elseif ($corporation !== null && !in_array($corporation, $configured, true)) {
                $leftOver[$corporation] = ($leftOver[$corporation] ?? 0) + 1;
            }
        }

        $duplicates = array_map(fn (array $copies) => $copies[0] . ' (' . count($copies) . ' rows)', array_values(array_filter($places, fn (array $copies) => count($copies) > 1)));

        $checks = [
            $unknown
                ? $this->check('Keys the plugin does not keep', 'warn', 'Nothing reads these: ' . $this->named($unknown) . '.')
                : $this->check('Keys the plugin does not keep', 'ok', 'Every stored key is one the plugin keeps.'),
        ];

        if ($moved) {
            $cleaned = Schema::hasTable('migrations') && DB::table('migrations')->where('migration', self::CLEANUP)->exists();

            $checks[] = $this->clearedOnce($cleaned, 'Retired settings', $retired, ['row', 'rows'], 'The plugin no longer uses them', 'None stored.');
            $checks[] = $this->clearedOnce($cleaned, 'Install-wide settings under a corporation', $installElsewhere, ['copy', 'copies'], 'Nothing reads them: the value in use is the one kept with no corporation', 'Every install-wide setting is kept once, with no corporation.');
            $checks[] = $this->clearedOnce($cleaned, 'Plugin records under a corporation', $recordsElsewhere, ['copy', 'copies'], 'Nothing reads them: the record in use is the one kept with no corporation', 'Every record the plugin keeps for itself is kept once, with no corporation.');

            $checks[] = $leftOver
                ? $this->check('Profiles of corporations taken off the list', 'info', 'Kept for ' . $this->named(array_map(fn (int $id, int $count) => $this->label($id) . " ({$count} values)", array_keys($leftOver), $leftOver)) . '. They tax nobody, and a corporation added again starts from a fresh copy.')
                : $this->check('Profiles of corporations taken off the list', 'ok', 'Only configured corporations have profiles.');
        }

        $checks[] = $duplicates
            ? $this->check('More than one row in one place', 'warn', $this->named($duplicates) . '. The plugin reads the oldest and ignores the rest.')
            : $this->check('More than one row in one place', 'ok', 'Each setting has one row in each place it is kept.');

        $checks[] = $unreadable
            ? $this->check('Values that do not read back', 'warn', $this->named($unreadable) . '. Saving the page that holds each one stores it again properly.')
            : $this->check('Values that do not read back', 'ok', 'Every stored value reads back as the type it was saved as.');

        if ($archive = $this->archive($moved)) {
            $checks[] = $archive;
        }

        return $checks;
    }

    /**
     * Rows migration 000039 clears once settings have moved. Before it runs
     * they are what the move left in place; after it, something has written
     * them since.
     */
    private function clearedOnce(bool $cleaned, string $label, array $places, array $noun, string $why, string $none): array
    {
        if (!$places) {
            return $this->check($label, 'ok', $none);
        }

        $counted = count($places) . ' ' . $noun[count($places) === 1 ? 0 : 1];

        return $cleaned
            ? $this->check($label, 'warn', 'Stored after migration 000039 cleared them: ' . $this->named($places) . ". {$why}, so whatever writes them is worth finding.")
            : $this->check($label, 'info', "{$counted}, left in place by the move. {$why}. Migration 000039 archives and removes them when it runs.");
    }

    private function archive(bool $moved): ?array
    {
        if (!Schema::hasTable(self::ARCHIVE)) {
            return $moved
                ? $this->check('Archive', 'warn', 'The mining_manager_settings_archive table is missing, so the copy of every settings row from before the move is gone with it.')
                : null;
        }

        $reasons = [
            'before_tax_profiles' => 'kept from before the move',
            'retired_settings' => 'retired settings cleared by migration 000039',
        ];

        $kept = DB::table(self::ARCHIVE)
            ->selectRaw('reason, COUNT(*) AS rows_kept, MAX(archived_at) AS archived_at')
            ->groupBy('reason')
            ->get()
            ->map(fn ($group) => sprintf(
                '%d rows %s%s',
                $group->rows_kept,
                $reasons[$group->reason] ?? "archived as {$group->reason}",
                $group->archived_at ? ' on ' . substr($group->archived_at, 0, 10) : ''
            ))
            ->all();

        return $this->check('Archive', 'info', $kept ? ucfirst(implode(', ', $kept)) . '.' : 'Empty.');
    }

    /**
     * Why a stored value would not read back as what was saved, or null.
     * Says what is wrong without saying what the value is.
     */
    private function unreadable(object $row): ?string
    {
        $value = $row->value;

        switch ($row->type) {
            case 'boolean':
                return in_array((string) $value, ['0', '1', ''], true) ? null : 'a switch not stored as 0 or 1';
            case 'json':
            case 'array':
                if ($value === null || $value === '') {
                    return null;
                }

                $decoded = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return 'a list that does not parse';
                }

                // Every list the plugin keeps is an array or an object. Text
                // here is a list encoded twice, as imports did before 3.0.0.
                return is_string($decoded) ? 'a list encoded twice' : null;
            case 'integer':
            case 'float':
            case 'decimal':
                if ($value === null || $value === '') {
                    return 'an empty number, read as 0';
                }

                return is_numeric($value) ? null : 'a number that does not parse';
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Listings
    // -----------------------------------------------------------------

    /**
     * Every profile side by side, each value marked when it was never saved
     * and reads the built-in default.
     */
    private function profileTable(?int $moonOwner, array $configured): array
    {
        $profiles = array_merge([null], $configured);

        $columns = array_map(fn (?int $profile) => [
            'label' => $this->label($profile),
            'note' => $profile === null ? ($configured ? 'Non-members' : 'Everyone') : ($profile === $moonOwner ? 'Moon Owner' : 'Configured'),
        ], $profiles);

        $row = fn (string $label, array $keys, \Closure $text, bool $othersOre = false) => [
            'label' => $label,
            'keys' => $keys,
            'cells' => array_map(function (?int $profile) use ($keys, $text, $othersOre, $configured) {
                $unused = $othersOre && $this->othersUnused($profile, $configured);

                return [
                    'text' => $text($this->profile($profile)),
                    'saved' => collect($keys)->every(fn (string $key) => $this->rowAt($key, $profile) !== null),
                    'unused' => $unused,
                ];
            }, $profiles),
        ];

        $moon = [];
        foreach (self::RARITIES as $rarity => $name) {
            $moon[] = $row("{$name} rate", ["tax_rates.moon_ore.{$rarity}"], fn (array $values) => $this->percent($values['rates']['moon_ore'][$rarity] ?? 0));
        }
        $moon[] = $row('Moon ore taxed', array_values(self::MOON_SELECTORS), fn (array $values) => ucfirst(self::MOON_CHOICES[$values['moon']]));

        $others = [];
        foreach (self::OTHERS as $key => $name) {
            $others[] = $row("{$name} rate", ["tax_rates.{$key}"], fn (array $values) => $this->percent($values['rates'][$key] ?? 0), true);
            $others[] = $row("{$name} taxed", ["tax_selector.{$key}"], fn (array $values) => !empty($values['selector'][$key]) ? 'Yes' : 'No', true);
        }

        $queued = $row('Queued change', self::QUEUE_KEYS, function (array $values) {
            if (!$change = $this->queued($values)) {
                return 'None';
            }

            [$to, $from] = $change;

            return ucfirst(self::PERIODS[$to] ?? $to) . ' from ' . ($this->date($from)?->toDateString() ?? $from);
        });
        // No queued change is stored as no rows, which is not a default.
        foreach ($queued['cells'] as $i => $cell) {
            $queued['cells'][$i]['saved'] = $cell['text'] === 'None' ? null : $cell['saved'];
        }

        return [
            'columns' => $columns,
            'unused_note' => "Not used: non-members' own mining is never taxed, only their mining at your refineries.",
            'sections' => [
                ['title' => 'Moon ore', 'rows' => $moon],
                ['title' => 'Other mining', 'rows' => $others],
                ['title' => 'Billing', 'rows' => [
                    $row('Billing period', [self::PERIOD_KEY], fn (array $values) => ucfirst(self::PERIODS[$values['period']] ?? $values['period'])),
                    $queued,
                ]],
                ['title' => 'Payment codes', 'rows' => [
                    $row('Code prefix', [self::PREFIX_KEY], fn (array $values) => $values['prefix']),
                ]],
            ],
        ];
    }

    /**
     * Every install-wide setting as stored, or the default it reads when it
     * was never saved. Secrets show only whether they are set.
     */
    private function installTable(): array
    {
        return array_map(function (string $key) {
            $row = $this->rowAt($key, null);

            return [
                'key' => $key,
                'value' => $row ? $this->shown($key, $row->value, $row->type) : $this->shown($key, $this->stored(config("mining-manager.{$key}")), null),
                'saved' => $row !== null,
            ];
        }, SettingsRegistry::keys(SettingsRegistry::INSTALL));
    }

    /**
     * The plugin's own records: cutover dates, last-sent times, the move.
     */
    private function recordTable(): array
    {
        return array_map(function (string $key) {
            $row = $this->rowAt($key, null);

            return [
                'key' => $key,
                'value' => $row ? $this->shown($key, $row->value, $row->type) : null,
                'updated_at' => $row->updated_at ?? null,
            ];
        }, SettingsRegistry::keys(SettingsRegistry::STATE));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * What a profile holds, read once. Global is null.
     */
    private function profile(?int $profile): array
    {
        $slot = $profile ?? 'global';

        if (!isset($this->profiles[$slot])) {
            $read = fn (string $key, $default = null) => $this->settings->getSettingForCorporation($key, $profile, $default);
            $selector = $this->settings->taxSelectorFor($profile);

            $this->profiles[$slot] = [
                'rates' => $this->settings->taxRatesFor($profile),
                'selector' => $selector,
                // Decided as the tax calculation decides it: no moon ore wins,
                // then only the Moon Owner's.
                'moon' => !empty($selector['no_moon_ore']) ? 'none'
                    : (!empty($selector['only_corp_moon_ore']) ? 'ours' : (($selector['all_moon_ore'] ?? true) ? 'all' : 'none')),
                'period' => (string) $read(self::PERIOD_KEY, 'monthly'),
                'pending' => $read(self::QUEUE_KEYS[0]),
                'pending_from' => $read(self::QUEUE_KEYS[1]),
                'prefix' => trim((string) $read(self::PREFIX_KEY, 'TAX-')),
            ];
        }

        return $this->profiles[$slot];
    }

    /**
     * A profile's queued change of billing period as [period, from], or null.
     * Half a queue is no queue, and neither is a change to the period
     * already in use.
     */
    private function queued(array $values): ?array
    {
        if (!$values['pending'] || !$values['pending_from'] || $values['pending'] === $values['period']) {
            return null;
        }

        return [(string) $values['pending'], (string) $values['pending_from']];
    }

    /**
     * Global's rates for ore, ice and gas tax nobody while corporations are
     * configured: non-members' own mining is never taxed.
     */
    private function othersUnused(?int $profile, array $configured): bool
    {
        return $profile === null && !empty($configured);
    }

    private function isOtherOre(string $key): bool
    {
        foreach (array_keys(self::OTHERS) as $other) {
            if ($key === "tax_rates.{$other}" || $key === "tax_selector.{$other}") {
                return true;
            }
        }

        return false;
    }

    private function rowAt(string $key, ?int $corporationId): ?object
    {
        return $this->placed[$key][$corporationId ?? 'global'] ?? null;
    }

    private function keyLabel(string $key): string
    {
        if (in_array($key, self::MOON_SELECTORS, true)) {
            return 'moon ore choice';
        }

        foreach (self::RARITIES as $rarity => $name) {
            if ($key === "tax_rates.moon_ore.{$rarity}") {
                return "{$name} rate";
            }
        }

        foreach (self::OTHERS as $other => $name) {
            if ($key === "tax_rates.{$other}") {
                return strtolower($name) . ' rate';
            }
            if ($key === "tax_selector.{$other}") {
                return strtolower($name) . ' taxed';
            }
        }

        return [self::PERIOD_KEY => 'billing period', self::PREFIX_KEY => 'code prefix'][$key] ?? $key;
    }

    private function label(?int $corporationId): string
    {
        if ($corporationId === null) {
            return 'Global';
        }

        $corporation = $this->corporations[$corporationId] ?? null;

        return $corporation ? "[{$corporation->ticker}] {$corporation->name}" : "Corporation {$corporationId}";
    }

    /**
     * A profile as named in a sentence: Global, or the corporation's ticker.
     */
    private function profileName(?int $profile): string
    {
        if ($profile === null) {
            return 'Global';
        }

        $ticker = $this->corporations[$profile]->ticker ?? null;

        return $ticker ? "[{$ticker}]" : "Corporation {$profile}";
    }

    private function percent($rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') . '%';
    }

    private function rateList(array $rates): string
    {
        return implode(', ', array_map(fn (string $rarity, float $rate) => self::RARITIES[$rarity] . ' ' . $this->percent($rate), array_keys($rates), $rates));
    }

    private function date($value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A list for a sentence, cut short when it runs long.
     */
    private function named(array $items): string
    {
        $shown = array_slice($items, 0, self::NAMED);
        $rest = count($items) - count($shown);

        return implode('; ', $shown) . ($rest > 0 ? "; and {$rest} more" : '');
    }

    /**
     * A config default as the settings table would store it.
     */
    private function stored($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }

    private function shown(string $key, ?string $value, ?string $type): ?string
    {
        if ($type === 'boolean' || ($type === null && in_array($value, ['0', '1'], true) && is_bool(config("mining-manager.{$key}")))) {
            return $value === '1' ? 'on' : 'off';
        }

        return SettingsTransfer::display($key, $value);
    }

    private function check(string $label, string $status, string $message): array
    {
        return ['label' => $label, 'status' => $status, 'message' => $message];
    }
}
