<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Console\Commands;

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\Migrator;
use Cline\Toggl\Migrators\PennantMigrator;
use Cline\Toggl\Migrators\YLSIdeasMigrator;
use Closure;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

use function array_key_exists;
use function assert;
use function collect;
use function count;
use function ctype_digit;
use function is_string;
use function json_encode;
use function mb_substr;
use function sprintf;

/**
 * Artisan command to migrate feature flags from external systems.
 *
 * Imports feature flag data from supported third-party systems (Laravel Pennant,
 * YLSIdeas Feature Flags) into Toggl, preserving feature definitions and context
 * values. The command can migrate from a specific system or all configured systems.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'toggl:migrate
                            {migrator? : Specific migrator to run (pennant, ylsideas)}
                            {--truncate : Truncate Toggl features table before migration}
                            {--since= : Only migrate records updated at or after the given datetime}
                            {--progress-every= : Log progress every N records (default: 10000, 0 to disable)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate feature flags from external systems to Toggl';

    /**
     * Execute the console command.
     *
     * @return int Command exit code
     */
    public function handle(Driver $driver): int
    {
        $since = $this->parseSinceOption();

        if ($since === false) {
            return self::FAILURE;
        }

        $progressEvery = $this->parseProgressEveryOption();

        if ($progressEvery === false) {
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            $this->truncateFeatures();
        }

        $migratorName = $this->argument('migrator');

        if (is_string($migratorName)) {
            return $this->runMigrator($migratorName, $driver, $since, $progressEvery);
        }

        // Run all configured migrators
        $migrators = ['pennant', 'ylsideas'];
        $totalStats = [
            'features' => 0,
            'contexts' => 0,
            'errors' => [],
        ];

        foreach ($migrators as $name) {
            if (!(bool) Config::get(sprintf('toggl.migrators.%s.enabled', $name), false)) {
                $this->components->info(sprintf('Skipping %s migrator (disabled)', $name));

                continue;
            }

            $this->components->info(sprintf('Running %s migrator...', $name));
            $stats = $this->executeMigrator($name, $driver, $since, $progressEvery);

            if (!$stats) {
                continue;
            }

            $totalStats['features'] += $stats['features'];
            $totalStats['contexts'] += $stats['contexts'];
            $totalStats['errors'] = [...$totalStats['errors'], ...$stats['errors']];
        }

        $this->displayResults($totalStats);

        return self::SUCCESS;
    }

    /**
     * Run a specific migrator by name.
     *
     * @param  string $name   The migrator name (pennant, ylsideas)
     * @param  Driver $driver Toggl driver instance
     * @return int    Command exit code
     */
    private function runMigrator(string $name, Driver $driver, ?DateTimeInterface $since, ?int $progressEvery): int
    {
        if (!(bool) Config::get(sprintf('toggl.migrators.%s.enabled', $name), false)) {
            $this->components->warn(sprintf("Migrator '%s' is disabled in configuration.", $name));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Running %s migrator...', $name));
        $stats = $this->executeMigrator($name, $driver, $since, $progressEvery);

        if (!$stats) {
            $this->components->error('Unknown migrator: '.$name);

            return self::FAILURE;
        }

        $this->displayResults($stats);

        return self::SUCCESS;
    }

    /**
     * Execute a migrator and return its statistics.
     *
     * @param  string                                                                                                                                                                                                                                                   $name   The migrator name
     * @param  Driver                                                                                                                                                                                                                                                   $driver Toggl driver instance
     * @return null|array{features: int, contexts: int, errors: array<string>, migrations?: array<array{pennant_id: mixed, pennant_scope: string, pennant_value: mixed, toggl_id: mixed, context_type: string, context_id: mixed, toggl_value: mixed, action: string}>} Migration statistics or null if unknown migrator
     */
    private function executeMigrator(string $name, Driver $driver, ?DateTimeInterface $since, ?int $progressEvery): ?array
    {
        $migrator = $this->createMigrator($name, $driver, $since, $progressEvery);

        if (!$migrator instanceof Migrator) {
            return null;
        }

        try {
            $migrator->migrate();

            return $migrator->getStatistics();
        } catch (Throwable $throwable) {
            $this->components->error('Migration failed: '.$throwable->getMessage());

            return [
                'features' => 0,
                'contexts' => 0,
                'errors' => [$throwable->getMessage()],
            ];
        }
    }

    /**
     * Create a migrator instance based on the name.
     *
     * @param  string        $name   The migrator name
     * @param  Driver        $driver Toggl driver instance
     * @return null|Migrator The migrator instance or null if unknown
     */
    private function createMigrator(string $name, Driver $driver, ?DateTimeInterface $since, ?int $progressEvery): ?Migrator
    {
        $progressCallback = $this->createProgressCallback($progressEvery);

        return match ($name) {
            'pennant' => new PennantMigrator(
                driver: $driver,
                table: Config::string('toggl.migrators.pennant.table', 'features'),
                connection: $this->getConnectionString('toggl.migrators.pennant.connection'),
                migrationTracking: (bool) Config::get('toggl.migrators.pennant.migration_tracking.enabled', false),
                trackingColumn: Config::string('toggl.migrators.pennant.migration_tracking.column', 'migrated_at'),
                since: $since,
                progressCallback: $progressCallback,
                progressEvery: $progressEvery ?? 10_000,
            ),
            'ylsideas' => new YLSIdeasMigrator(
                driver: $driver,
                table: Config::string('toggl.migrators.ylsideas.table', 'features'),
                field: Config::string('toggl.migrators.ylsideas.field', 'active_at'),
                connection: $this->getConnectionString('toggl.migrators.ylsideas.connection'),
                migrationTracking: (bool) Config::get('toggl.migrators.ylsideas.migration_tracking.enabled', false),
                trackingColumn: Config::string('toggl.migrators.ylsideas.migration_tracking.column', 'migrated_at'),
                since: $since,
                progressCallback: $progressCallback,
                progressEvery: $progressEvery ?? 10_000,
            ),
            default => null,
        };
    }

    /**
     * Get a connection string from configuration.
     *
     * @param  string      $key Configuration key
     * @return null|string Connection string or null
     */
    private function getConnectionString(string $key): ?string
    {
        $value = Config::get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Parse the --since option into a DateTimeInterface or null.
     *
     * @return null|DateTimeInterface|false Parsed date, null if not provided, or false if invalid
     */
    private function parseSinceOption(): DateTimeInterface|false|null
    {
        $since = $this->option('since');

        if (!is_string($since) || $since === '') {
            return null;
        }

        try {
            return Date::parse($since);
        } catch (Throwable $throwable) {
            $this->components->error(sprintf("Invalid --since value '%s': %s", $since, $throwable->getMessage()));

            return false;
        }
    }

    /**
     * Parse the --progress-every option into an integer or null.
     *
     * @return null|false|int Parsed integer, null if not provided, or false if invalid
     */
    private function parseProgressEveryOption(): int|false|null
    {
        $value = $this->option('progress-every');

        if (!is_string($value) || $value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            $this->components->error(sprintf("Invalid --progress-every value '%s': must be a non-negative integer.", $value));

            return false;
        }

        return (int) $value;
    }

    /**
     * Create a progress callback for migrators.
     *
     * @param null|int $progressEvery Log progress every N records, or null to use default
     */
    private function createProgressCallback(?int $progressEvery): ?Closure
    {
        if ($progressEvery === 0) {
            return null;
        }

        return function (int $processed, int $successful): void {
            $this->components->info(sprintf('Processed %d record(s), migrated %d.', $processed, $successful));
        };
    }

    /**
     * Display migration results to the user.
     *
     * @param array{features: int, contexts: int, errors: array<string>, migrations?: array<array{pennant_id: mixed, pennant_scope: string, pennant_value: mixed, toggl_id: mixed, context_type: string, context_id: mixed, toggl_value: mixed, action: string}>} $stats Migration statistics
     */
    private function displayResults(array $stats): void
    {
        if ($stats['features'] === 0 && $stats['contexts'] === 0) {
            $this->components->info('No features migrated.');

            return;
        }

        $this->components->info(sprintf('Successfully migrated %d feature(s) with %d context(s).', $stats['features'], $stats['contexts']));

        if (array_key_exists('migrations', $stats) && $stats['migrations'] !== []) {
            $this->newLine();
            $this->components->info('Migration Details ('.count($stats['migrations']).' records):');
            $this->table(
                ['Pennant ID', 'Scope', 'Pennant Value', 'Toggl ID', 'Type', 'Context ID', 'Toggl Value', 'Action'],
                collect($stats['migrations'])->map(fn (array $m): array => [
                    $m['pennant_id'],
                    $m['pennant_scope'],
                    mb_substr((string) json_encode($m['pennant_value']), 0, 30),
                    $m['toggl_id'] ?? 'N/A',
                    $m['context_type'],
                    $m['context_id'] ?? 'N/A',
                    mb_substr((string) json_encode($m['toggl_value']), 0, 30),
                    $m['action'],
                ])->all(),
            );
        }

        if (empty($stats['errors'])) {
            return;
        }

        $this->newLine();
        $this->components->warn('Encountered '.count($stats['errors']).' error(s) during migration:');

        foreach ($stats['errors'] as $error) {
            $this->line('  - '.$error);
        }
    }

    /**
     * Truncate the Toggl features table.
     */
    private function truncateFeatures(): void
    {
        $connection = Config::get('toggl.stores.database.connection', Config::get('database.default'));
        $table = Config::get('toggl.table_names.features', 'features');
        assert(is_string($table), 'Table name must be a string');
        assert(is_string($connection) || null === $connection, 'Connection name must be a string or null');

        $connectionName = $connection ?? 'default';
        $this->components->warn(sprintf("Truncating table '%s' on connection '%s'...", $table, $connectionName));

        $query = $connection === null
            ? DB::table($table)
            : DB::connection($connection)->table($table);

        $query->truncate();

        $this->components->info('Table truncated successfully.');
    }
}
