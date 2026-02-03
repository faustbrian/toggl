<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Migrators;

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Contracts\Migrator;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use stdClass;
use Throwable;

use function assert;
use function is_int;
use function is_string;
use function now;
use function property_exists;
use function sprintf;

/**
 * Migrator for importing feature flags from YLSIdeas Feature Flags.
 *
 * This migrator reads feature flag data from YLSIdeas Feature Flags' database storage
 * and imports it into the Toggl feature flag system. YLSIdeas stores features as simple
 * on/off toggles with timestamps, which are converted to boolean values.
 *
 * @phpstan-type YLSIdeasRecord stdClass&object{feature: string}
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class YLSIdeasMigrator implements Migrator
{
    /**
     * Statistics tracking the migration process.
     *
     * Tracks the number of successfully migrated features and contexts, as well
     * as any errors encountered during migration for post-migration analysis.
     *
     * @var array{features: int, contexts: int, errors: array<string>}
     */
    private array $statistics = [
        'features' => 0,
        'contexts' => 0,
        'errors' => [],
    ];

    /**
     * Create a new YLSIdeas migrator instance.
     *
     * @param Driver                 $driver            The target Toggl driver to migrate features into
     * @param string                 $table             The YLSIdeas features table name (default: 'features')
     * @param string                 $field             The field name for active status (default: 'active_at')
     * @param null|string            $connection        The database connection name (null for default)
     * @param bool                   $migrationTracking Whether to track migration timestamps (default: false)
     * @param string                 $trackingColumn    Column name for migration tracking (default: 'migrated_at')
     * @param null|DateTimeInterface $since             Only migrate records updated at or after this time
     * @param null|Closure           $progressCallback  Callback to report progress
     * @param int                    $progressEvery     Report progress every N records
     * @param int                    $chunkSize         Chunk size for batch processing when possible
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly string $table = 'features',
        private readonly string $field = 'active_at',
        private readonly ?string $connection = null,
        private readonly bool $migrationTracking = false,
        private readonly string $trackingColumn = 'migrated_at',
        private readonly ?DateTimeInterface $since = null,
        private readonly ?Closure $progressCallback = null,
        private readonly int $progressEvery = 10_000,
        private readonly int $chunkSize = 1_000,
    ) {}

    /**
     * Execute the migration from YLSIdeas Feature Flags to Toggl.
     *
     * Imports all feature flags from YLSIdeas' database storage into Toggl as
     * global (null context) boolean toggles. YLSIdeas features are simple on/off
     * switches, so they migrate as true/false values based on the activation
     * timestamp field. Individual feature failures are logged but don't halt
     * the overall migration process.
     *
     * @throws Throwable When a critical migration error occurs during feature fetching
     */
    public function migrate(): void
    {
        $this->statistics = [
            'features' => 0,
            'contexts' => 0,
            'errors' => [],
        ];

        try {
            $features = $this->fetchAllFeatures();
            $processed = 0;
            $successful = 0;

            /** @var stdClass $feature */
            foreach ($features as $feature) {
                try {
                    /** @var object{feature: string}&stdClass $feature */
                    $this->migrateFeature($feature);
                    ++$this->statistics['features'];
                    ++$this->statistics['contexts'];
                    ++$successful;
                } catch (Throwable $e) {
                    $featureName = property_exists($feature, 'feature') ? $feature->feature : 'unknown';
                    $this->statistics['errors'][] = sprintf("Failed to migrate feature '%s': %s", $featureName, $e->getMessage());
                }

                ++$processed;
                $this->maybeReportProgress($processed, $successful);
            }
        } catch (Throwable $throwable) {
            $this->statistics['errors'][] = 'Migration failed: '.$throwable->getMessage();

            throw $throwable;
        }
    }

    /**
     * Retrieve migration statistics.
     *
     * Provides a summary of the migration results including successful feature
     * and context counts, as well as any errors encountered during the process.
     *
     * @return array{features: int, contexts: int, errors: array<string>} Migration statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Stream all features from YLSIdeas' database storage.
     *
     * Retrieves feature records using a lazy cursor to avoid loading
     * the full table into memory.
     *
     * @return LazyCollection<int, stdClass>
     */
    private function fetchAllFeatures(): LazyCollection
    {
        $query = DB::connection($this->connection)
            ->table($this->table);

        if ($this->migrationTracking) {
            $query->whereNull($this->trackingColumn);
        }

        if ($this->since instanceof \DateTimeInterface) {
            $query->where('updated_at', '>=', $this->since);
        }

        $connection = $this->connection;

        if (Schema::connection($connection)->hasColumn($this->table, 'id')) {
            return $query->orderBy('id')->lazyById($this->chunkSize, column: 'id');
        }

        return $query->orderBy('feature')->cursor();
    }

    /**
     * Migrate a single feature from YLSIdeas to Toggl.
     *
     * YLSIdeas stores features as global toggles with a nullable timestamp field.
     * If the field is non-null, the feature is active; otherwise it's inactive.
     * The feature is migrated to Toggl as a global feature (all contexts) with a boolean value.
     *
     * @param stdClass $feature The YLSIdeas feature record with 'feature' name and activation field
     *
     * @phpstan-param object{feature: string}&stdClass $feature
     */
    private function migrateFeature(stdClass $feature): void
    {
        $isActive = !empty($feature->{$this->field});

        /** @var string $featureName */
        $featureName = $feature->feature;

        $this->driver->setForAllContexts($featureName, $isActive);

        // Mark record as migrated
        $recordId = property_exists($feature, 'id') ? $feature->id : null;
        $this->markRecordAsMigrated($recordId);
    }

    /**
     * Mark a YLSIdeas record as migrated by updating its timestamp.
     */
    private function markRecordAsMigrated(mixed $recordId): void
    {
        if (!$this->migrationTracking) {
            return;
        }

        if ($recordId === null) {
            $this->statistics['errors'][] = 'Cannot mark record as migrated: missing ID';

            return;
        }

        assert(is_int($recordId) || is_string($recordId));

        try {
            DB::connection($this->connection)
                ->table($this->table)
                ->where('id', $recordId)
                ->update([$this->trackingColumn => now()]);
        } catch (Throwable $throwable) {
            $this->statistics['errors'][] = sprintf(
                'Failed to mark record %s as migrated: %s',
                $recordId,
                $throwable->getMessage(),
            );
        }
    }

    /**
     * Report migration progress when configured.
     */
    private function maybeReportProgress(int $processed, int $successful): void
    {
        if (!$this->progressCallback instanceof \Closure) {
            return;
        }

        if ($this->progressEvery <= 0) {
            return;
        }

        if ($processed % $this->progressEvery !== 0) {
            return;
        }

        ($this->progressCallback)($processed, $successful);
    }
}
