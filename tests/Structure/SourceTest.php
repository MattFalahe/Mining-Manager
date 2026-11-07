<?php

namespace MiningManager\Tests\Structure;

use MiningManager\Services\Configuration\SettingsRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Things a mistake in the source would break on a live install that nothing
 * else catches before then, read from the files themselves.
 */
class SourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const SRC = self::ROOT . 'src/';
    private const HELP = 'src/Resources/views/help/index.blade.php';
    private const HELP_TEXT = 'src/Resources/lang/en/help.php';

    /** Released migrations are frozen, so naming the plugin in the file only binds those from here on. */
    private const FIRST_MIGRATION_NAMING_THE_PLUGIN = 31;

    /** Released before the rule, and a released migration is never edited. It runs the same. */
    private const ANONYMOUS_BUT_RELEASED = ['2026_01_01_000017_add_metenox_cargo_full_notification.php'];

    /**
     * The only writes outside the plugin's own tables. The test data tools make
     * up corporations and characters on a development install and clear them
     * again. Manager Core's table is a fallback for when its own call is not
     * there, and only ever removes our rows. No migration makes the historical
     * prices table, and nothing calls the methods that name it.
     */
    private const WRITES_ELSEWHERE = [
        'DiagnosticController.php' => ['corporation_infos', 'character_infos', 'character_affiliations'],
        'GenerateTestDataCommand.php' => ['corporation_infos', 'character_infos', 'character_affiliations'],
        'MarketDataService.php' => ['mining_historical_prices'],
        'PriceProviderService.php' => ['manager_core_type_subscriptions'],
    ];

    /** @return string[] paths of the files under src/ with this ending */
    private static function files(string $ending, string $under = ''): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC . $under, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), $ending)) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    private static function migrations(): string
    {
        $source = '';
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $source .= file_get_contents($path);
        }

        return $source;
    }

    /** @return string[] every table a migration creates, which is what the plugin owns */
    private static function pluginTables(): array
    {
        preg_match_all("/Schema::create\(\s*'([a-zA-Z_]+)'/", self::migrations(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string, string[]> every command, with its options */
    private static function commands(): array
    {
        $known = [];
        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            self::assertSame(1, preg_match("/signature = 'mining-manager:([a-z-]+)(.*?)';/s", file_get_contents($path), $signature), basename($path));
            preg_match_all('/\{--([a-z0-9_-]+)/', $signature[2], $options);
            $known[$signature[1]] = $options[1];
        }

        return $known;
    }

    /**
     * Blade reads a directive with \B@\w+, so counting @if and @endif proves
     * nothing: {{ $h }}h@endif is not a directive, and leaves its @if open.
     */
    public function test_every_blade_directive_is_closed_the_way_blade_reads_it(): void
    {
        $closes = [
            'endif' => 'if', 'endunless' => 'unless', 'endisset' => 'isset', 'endforeach' => 'foreach',
            'endforelse' => 'forelse', 'endfor' => 'for', 'endwhile' => 'while', 'endpush' => 'push',
            'endcan' => 'can', 'endcannot' => 'cannot', 'endcanany' => 'canany', 'endswitch' => 'switch',
            'endauth' => 'auth', 'endguest' => 'guest', 'enderror' => 'error', 'endonce' => 'once',
            'endprepend' => 'prepend', 'endempty' => 'empty',
            'endsection' => 'section', 'stop' => 'section', 'show' => 'section', 'overwrite' => 'section',
        ];
        $opens = array_flip($closes);

        foreach (self::files('.blade.php') as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            $source = preg_replace('/\B@verbatim\b.*?\B@endverbatim\b/s', '', $source);
            $source = preg_replace('/\B@php\b(?!\s*\().*?\B@endphp\b/s', '', $source);
            // A section with its content in the call has nothing to close.
            $source = preg_replace("/\B@section\(\s*'[^']*'\s*,.*\)\s*$/m", '', $source);

            preg_match_all('/(?<!@)\B@(\w+)[ \t]*(\()?/', $source, $matches, PREG_SET_ORDER);
            $stack = [];
            foreach ($matches as $match) {
                $directive = $match[1];

                // With nothing after it, @empty is the middle of a @forelse.
                if ($directive === 'empty' && !isset($match[2])) {
                    continue;
                }

                if ($directive === 'hasSection') {
                    $stack[] = 'if';
                } elseif (isset($opens[$directive])) {
                    $stack[] = $directive;
                } elseif (isset($closes[$directive])) {
                    $this->assertSame($closes[$directive], array_pop($stack), basename($path) . ": @$directive closes the wrong block");
                }
            }

            $this->assertSame([], $stack, basename($path) . ': left open');
        }
    }

    public function test_every_language_key_a_literal_call_names_exists(): void
    {
        $lang = [];
        foreach (self::files('.php', 'Resources/lang/en') as $path) {
            $lang[basename($path, '.php')] = require $path;
        }

        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\b(?:trans|__|trans_choice)\(|@lang\()\s*'mining-manager::([a-z_]+)\.([a-zA-Z0-9_.]+)'\s*[,)]/", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $file, $key]) {
                $value = $lang[$file] ?? null;
                foreach (explode('.', $key) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertNotNull($value, basename($path) . " names $file.$key, which is not there");
            }
        }
    }

    public function test_migrations_are_named_classes_matching_their_file(): void
    {
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $source = file_get_contents($path);
            $number = (int) substr(basename($path), 11, 6);
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));

            if (!in_array(basename($path), self::ANONYMOUS_BUT_RELEASED, true)) {
                $this->assertStringNotContainsString('return new class', $source, basename($path));
                $this->assertMatchesRegularExpression('/\bclass ' . $class . ' extends Migration\b/', $source, basename($path));
            }

            if ($number >= self::FIRST_MIGRATION_NAMING_THE_PLUGIN) {
                $this->assertStringContainsString('mining_manager', $suffix, basename($path) . ' has to name the plugin: migrations share one namespace');
            }
        }
    }

    public function test_migration_numbers_run_in_order_with_none_repeated_and_one_date(): void
    {
        $names = array_map('basename', self::files('.php', 'Database/migrations'));

        $this->assertSame(range(1, count($names)), array_map(fn ($name) => (int) substr($name, 11, 6), $names));
        foreach ($names as $name) {
            $this->assertStringStartsWith('2026_01_01_', $name);
        }
    }

    public function test_index_names_fit_in_what_mysql_allows(): void
    {
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $source = file_get_contents($path);

            preg_match_all("/->(?:index|unique|primary)\(\s*(?:\[[^\]]*\]|'[^']*')\s*,\s*'([^']+)'/", $source, $named);
            foreach ($named[1] as $name) {
                $this->assertLessThanOrEqual(64, strlen($name), basename($path) . ": $name");
            }

            // Left to itself Laravel builds the name from the table and columns.
            foreach (preg_split('/(?=Schema::(?:create|table)\()/', $source) as $block) {
                if (preg_match("/^Schema::(?:create|table)\(\s*'([a-zA-Z_]+)'/", $block, $table)) {
                    preg_match_all("/->(index|unique)\(\s*\[([^\]]*)\]\s*\)/", $block, $unnamed, PREG_SET_ORDER);
                    foreach ($unnamed as [, $kind, $columns]) {
                        preg_match_all("/'([^']+)'/", $columns, $names);
                        $name = $table[1] . '_' . implode('_', $names[1]) . '_' . $kind;
                        $this->assertLessThanOrEqual(64, strlen($name), basename($path) . ": $name");
                    }
                }
            }
        }
    }

    /** SeAT's tables are read, never written. Our data lives in our own tables. */
    public function test_nothing_writes_a_table_that_is_not_the_plugins(): void
    {
        $ours = self::pluginTables();

        foreach (self::files('.php') as $path) {
            $allowed = array_merge($ours, self::WRITES_ELSEWHERE[basename($path)] ?? []);

            preg_match_all("/DB::table\('([a-zA-Z_]+)(?: as \w+)?'\)((?:(?!DB::table|;).)*?)->(insert|insertOrIgnore|insertUsing|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/s", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $table]) {
                $this->assertContains($table, $allowed, basename($path) . " writes $table");
            }
        }
    }

    /** Not a column, not an index: SeAT's schema is SeAT's. */
    public function test_nothing_changes_a_table_that_is_not_the_plugins(): void
    {
        $ours = self::pluginTables();

        foreach (self::files('.php', 'Database/migrations') as $path) {
            preg_match_all("/Schema::(?:table|drop|dropIfExists|rename)\(\s*'([a-zA-Z_]+)'/", file_get_contents($path), $named);
            foreach ($named[1] as $table) {
                $this->assertContains($table, $ours, basename($path));
            }
        }

        foreach (self::files('.php') as $path) {
            if (!str_contains(str_replace('\\', '/', $path), 'Database/migrations')) {
                $this->assertDoesNotMatchRegularExpression('/Schema::(create|table|drop|dropIfExists|rename)\(/', file_get_contents($path), basename($path) . ' changes a schema outside a migration');
            }
        }
    }

    /** It only ever brought back what was on its list, and fifteen tables were once missing from it. */
    public function test_a_backup_takes_every_table_the_plugin_owns(): void
    {
        $this->assertSame(1, preg_match('/PLUGIN_TABLES = \[(.*?)\];/s', file_get_contents(self::SRC . 'Console/Commands/BackupDataCommand.php'), $list));
        preg_match_all("/'([a-z_]+)'/", $list[1], $tables);

        $this->assertSame([], array_values(array_diff(self::pluginTables(), $tables[1])), 'left out of a backup');
    }

    public function test_every_command_is_registered(): void
    {
        $provider = file_get_contents(self::SRC . 'MiningManagerServiceProvider.php');

        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            $this->assertStringContainsString(basename($path, '.php') . '::class', $provider, basename($path));
        }
    }

    public function test_the_schedule_only_runs_commands_and_options_that_exist(): void
    {
        $known = self::commands();

        preg_match_all("/'command' => 'mining-manager:([a-z-]+)([^']*)'/", file_get_contents(self::SRC . 'Database/Seeders/ScheduleSeeder.php'), $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as [, $command, $arguments]) {
            $this->assertArrayHasKey($command, $known, "the schedule runs $command");
            preg_match_all('/--([a-z-]+)/', $arguments, $options);
            foreach ($options[1] as $option) {
                $this->assertContains($option, $known[$command], "the schedule runs $command --$option");
            }
        }
    }

    /** The Master Test warns about any of these lines the schedule does not have word for word. */
    public function test_what_the_master_test_expects_on_the_schedule_is_on_it(): void
    {
        $this->assertSame(1, preg_match('/\$expected = \[\s*((?:\'mining-manager:[^\']+\',\s*)+)\]/', file_get_contents(self::SRC . 'Services/Diagnostic/MasterTestRunner.php'), $list));
        preg_match_all("/'(mining-manager:[^']+)'/", $list[1], $expected);

        $seeder = file_get_contents(self::SRC . 'Database/Seeders/ScheduleSeeder.php');
        foreach ($expected[1] as $command) {
            $this->assertStringContainsString("'command' => '$command'", $seeder);
        }
    }

    public function test_every_route_reaches_a_method_that_exists(): void
    {
        preg_match_all("/'uses'\s*=>\s*'(\w+)@(\w+)'/", file_get_contents(self::SRC . 'Http/routes.php'), $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as [, $controller, $method]) {
            $path = self::SRC . "Http/Controllers/$controller.php";
            $this->assertFileExists($path);
            $this->assertMatchesRegularExpression('/\bfunction ' . $method . '\s*\(/', file_get_contents($path), "$controller@$method");
        }
    }

    /** A name that is not there is a fatal error when the page renders, not when it is deployed. */
    public function test_every_route_a_page_names_exists(): void
    {
        preg_match_all("/'as'\s*=>\s*'([^']+)'/", file_get_contents(self::SRC . 'Http/routes.php'), $declared);

        foreach (self::files('.php') as $path) {
            preg_match_all("/\broute\(\s*['\"](mining-manager\.[a-zA-Z0-9_.-]+)['\"]/", file_get_contents($path), $named);
            foreach ($named[1] as $route) {
                $this->assertContains($route, $declared[1], basename($path) . " names the route $route");
            }
        }
    }

    public function test_every_view_a_literal_call_names_exists(): void
    {
        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\bview\(|@include\(|@extends\(|@includeIf\()\s*'mining-manager::([a-zA-Z0-9_.-]+)'/", file_get_contents($path), $named);
            foreach ($named[1] as $view) {
                $this->assertFileExists(self::SRC . 'Resources/views/' . str_replace('.', '/', $view) . '.blade.php', basename($path));
            }
        }
    }

    public function test_every_permission_a_route_or_page_asks_for_exists(): void
    {
        $permissions = array_keys(require self::SRC . 'Config/Permissions/mining-manager.permissions.php');

        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:can:|@can\(\s*'|->can\(\s*'|Gate::\w+\(\s*'|hasPermission\(\s*')mining-manager\.([a-z_]+)/", file_get_contents($path), $named);
            foreach ($named[1] as $permission) {
                $this->assertContains($permission, $permissions, basename($path));
            }
        }
    }

    /** A switch left off one of these never saves, and nobody can tell until the alert does not come. */
    public function test_every_webhook_notification_type_has_its_column_form_and_validation(): void
    {
        $model = file_get_contents(self::SRC . 'Models/WebhookConfiguration.php');
        $this->assertSame(1, preg_match('/\$fillable = \[(.*?)\];/s', $model, $fillable));
        preg_match_all("/'(notify_[a-z_]+)'/", $fillable[1], $types);
        $this->assertNotEmpty($types[1]);

        $migrations = self::migrations();
        $form = file_get_contents(self::SRC . 'Resources/views/settings/tabs/webhooks.blade.php');
        $controller = file_get_contents(self::SRC . 'Http/Controllers/SettingsController.php');

        foreach ($types[1] as $column) {
            $this->assertStringContainsString("'$column'", $migrations, "$column has no column");
            $this->assertStringContainsString($column, $form, "$column is not on the webhook form");
            $this->assertStringContainsString("'$column'", $controller, "$column is not saved");
            $this->assertStringContainsString("'$column' => 'boolean'", $model, "$column is not cast");
        }
    }

    public function test_every_setting_the_code_names_is_registered(): void
    {
        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:[sS]ettings(?:Service|Manager)?|->settings|this)->(?:getSetting|updateSetting)\(\s*'([a-z_]+\.[a-z_0-9.]+)'/", file_get_contents($path), $named);
            foreach ($named[1] as $key) {
                $this->assertNotNull(SettingsRegistry::scope($key), basename($path) . " names the setting $key");
            }
        }
    }

    /** MC stays optional: every plugin has to work without it. */
    public function test_manager_core_is_never_required(): void
    {
        $composer = json_decode(file_get_contents(self::ROOT . 'composer.json'), true);

        $this->assertArrayNotHasKey('mattfalahe/manager-core', $composer['require']);
    }

    public function test_the_docs_cover_every_command_and_name_none_that_is_gone(): void
    {
        $known = self::commands();
        $help = file_get_contents(self::ROOT . self::HELP) . file_get_contents(self::ROOT . self::HELP_TEXT);

        foreach (['README.md' => file_get_contents(self::ROOT . 'README.md'), 'Help' => $help] as $name => $docs) {
            foreach (array_keys($known) as $command) {
                $this->assertStringContainsString("mining-manager:$command", $docs, "$name leaves out $command");
            }

            preg_match_all('/mining-manager:([a-z-]+)/', $docs, $named);
            foreach (array_unique($named[1]) as $command) {
                $this->assertArrayHasKey($command, $known, "$name names $command, which is not a command");
            }
        }

        foreach ($known as $command => $options) {
            foreach ($options as $option) {
                $this->assertMatchesRegularExpression('/--' . $option . '\b/', $help, "Help leaves out $command --$option");
            }
        }
    }

    /** These counts are in the README in so many words, and they go stale quietly. */
    public function test_the_readmes_counts_are_right(): void
    {
        $readme = file_get_contents(self::ROOT . 'README.md');

        preg_match_all("/'command' => 'mining-manager:([a-z-]+)/", file_get_contents(self::SRC . 'Database/Seeders/ScheduleSeeder.php'), $scheduled);
        $this->assertStringContainsString(count(self::commands()) . ' commands available, ' . count(array_unique($scheduled[1])) . ' of them on automated schedules', $readme);

        $this->assertSame(1, preg_match('/\$fillable = \[(.*?)\];/s', file_get_contents(self::SRC . 'Models/WebhookConfiguration.php'), $fillable));
        preg_match_all("/'notify_[a-z_]+'/", $fillable[1], $types);
        $this->assertStringContainsString(count($types[0]) . ' notification types', $readme);
    }

    public function test_the_master_test_is_described_with_the_number_of_checks_it_runs(): void
    {
        $this->assertSame(1, preg_match('/testMethods = \[(.*?)\];/s', file_get_contents(self::SRC . 'Services/Diagnostic/MasterTestRunner.php'), $list));
        preg_match_all("/'check[A-Za-z0-9]+'/", $list[1], $checks);
        $this->assertNotEmpty($checks[0]);

        foreach (['README.md', self::HELP, self::HELP_TEXT] as $file) {
            preg_match_all('/\b(\d+) checks\b/', file_get_contents(self::ROOT . $file), $said);
            $this->assertNotEmpty($said[1], $file);
            foreach ($said[1] as $number) {
                $this->assertSame(count($checks[0]), (int) $number, $file);
            }
        }
    }

    public function test_every_event_the_plugin_publishes_is_in_help(): void
    {
        $help = file_get_contents(self::ROOT . self::HELP);

        foreach (self::files('.php') as $path) {
            if (str_contains(str_replace('\\', '/', $path), 'Resources/')) {
                continue;
            }

            preg_match_all("/'(mining\.[a-z_]+)'/", file_get_contents($path), $named);
            foreach ($named[1] as $event) {
                $this->assertStringContainsString($event, $help, basename($path) . " names $event");
            }
        }
    }

    /** A package name with a branch in it stops working the day the branch goes, and people copy what the docs say. */
    public function test_the_docs_name_the_package_and_no_branch(): void
    {
        foreach (['README.md', 'CHANGELOG.md', self::HELP, self::HELP_TEXT] as $file) {
            $this->assertDoesNotMatchRegularExpression('/mining-manager:dev|mining-manager:\^?dev|dev-dev-|dev-7\.0/', file_get_contents(self::ROOT . $file), $file);
        }
    }

    /** Comments and docs are read by strangers on GitHub, and CSS comments reach every user's browser. */
    public function test_nothing_published_names_private_notes_or_local_paths(): void
    {
        $paths = array_merge([self::ROOT . 'README.md', self::ROOT . 'CHANGELOG.md'], self::files('.php'), self::files('.css'), self::files('.js'));

        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:feedback|project|reference)_[a-z0-9_]+\.md\b|\bfeedback_[a-z_]+|\bproject_(?:mining|manager|seat|hr|structure)_|\bMEMORY\.md\b|\bClaude\b|\bCo-Authored-By\b|[FC]:[\\\\\/](?:Claude|Users)/',
                file_get_contents($path),
                basename($path)
            );
        }
    }
}
