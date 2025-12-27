<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Drivers;

use Cline\Toggl\Contracts\CanListStoredFeatures;
use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\ValueObjects\FeatureValue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

use function config;
use function is_null;
use function json_decode;

/**
 * Laravel Pennant compatibility driver for gradual migration.
 *
 * This driver wraps the standard DatabaseDriver and intercepts read operations
 * to check Laravel Pennant's features table first before falling back to Toggl.
 * All write operations are delegated exclusively to Toggl's storage.
 *
 * Use this during migration when you have millions of feature flags in Pennant
 * that need to be migrated gradually while ensuring reads work from both sources.
 *
 * Configuration:
 * - toggl.pennant_compatibility.enabled: Enable compatibility mode
 * - toggl.pennant_compatibility.table: Pennant features table name (default: 'features')
 * - toggl.pennant_compatibility.connection: Database connection for Pennant table
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class PennantCompatibilityDriver implements CanListStoredFeatures, Driver
{
    /**
     * Create a new Pennant compatibility driver instance.
     *
     * @param DatabaseDriver $togglDriver    The underlying Toggl database driver for writes
     * @param string         $pennantTable   The Pennant features table name
     * @param string|null    $connectionName Database connection name for Pennant table
     */
    public function __construct(
        private readonly DatabaseDriver $togglDriver,
        private readonly string $pennantTable = 'features',
        private readonly ?string $connectionName = null,
    ) {}

    public function define(string $feature, mixed $resolver = null): mixed
    {
        return $this->togglDriver->define($feature, $resolver);
    }

    public function defined(): array
    {
        return $this->togglDriver->defined();
    }

    public function stored(): array
    {
        return $this->togglDriver->stored();
    }

    /**
     * Get multiple feature flag values, checking Pennant first.
     *
     * For each feature/context pair:
     * 1. Check Pennant's features table first
     * 2. If found in Pennant, return that value
     * 3. If not in Pennant, fall back to Toggl's table
     *
     * @param  array<string, array<int, TogglContext>> $features Map of feature names to contexts
     * @return array<string, array<int, mixed>>        Resolved values
     */
    public function getAll(array $features): array
    {
        $result = [];

        foreach ($features as $featureName => $contexts) {
            $result[$featureName] = [];

            foreach ($contexts as $index => $context) {
                $result[$featureName][$index] = $this->get($featureName, $context);
            }
        }

        return $result;
    }

    /**
     * Get a feature flag's value, checking Pennant first.
     *
     * Read priority:
     * 1. Check Laravel Pennant's features table
     * 2. If not found, check Toggl's features table
     *
     * @param  string       $feature Feature name
     * @param  TogglContext $context Context for the feature
     * @return FeatureValue Feature value
     */
    public function get(string $feature, TogglContext $context): FeatureValue
    {
        // Try Pennant first
        $pennantResult = $this->getFromPennant($feature, $context);

        // Check if feature was found in Pennant (returns array with 'found' and 'value')
        if ($pennantResult['found']) {
            // Wrap Pennant value in FeatureValue
            return FeatureValue::from($pennantResult['value']);
        }

        // Fall back to Toggl
        return $this->togglDriver->get($feature, $context);
    }

    /**
     * Set a feature flag's value (writes ONLY to Toggl).
     *
     * @param string       $feature Feature name
     * @param TogglContext $context Context to set the value for
     * @param mixed        $value   Value to persist
     */
    public function set(string $feature, TogglContext $context, mixed $value): void
    {
        $this->togglDriver->set($feature, $context, $value);
    }

    /**
     * Set a feature flag's value globally (writes ONLY to Toggl).
     *
     * @param string $feature Feature name
     * @param mixed  $value   Value to set globally
     */
    public function setForAllContexts(string $feature, mixed $value): void
    {
        $this->togglDriver->setForAllContexts($feature, $value);
    }

    /**
     * Delete a feature flag's stored value (deletes ONLY from Toggl).
     *
     * @param string       $feature Feature name
     * @param TogglContext $context Context to delete
     */
    public function delete(string $feature, TogglContext $context): void
    {
        $this->togglDriver->delete($feature, $context);
    }

    /**
     * Purge features from storage (purges ONLY from Toggl).
     *
     * @param null|array<int, string> $features Features to purge, or null for all
     */
    public function purge(?array $features): void
    {
        $this->togglDriver->purge($features);
    }

    /**
     * Retrieve a feature value from Laravel Pennant's features table.
     *
     * Pennant stores features with:
     * - name: feature name
     * - scope: serialized context identifier (or null for global)
     * - value: JSON-encoded feature value
     *
     * @param  string                                   $feature Feature name
     * @param  TogglContext                             $context Context to check
     * @return array{found: bool, value: mixed|null}    Result array with 'found' flag and value
     */
    private function getFromPennant(string $feature, TogglContext $context): array
    {
        $connection = $this->getConnection();
        $scope = $this->serializeContextForPennant($context);

        $record = $connection->table($this->pennantTable)
            ->where('name', $feature)
            ->where(fn ($query) => is_null($scope)
                ? $query->whereNull('scope')
                : $query->where('scope', $scope)
            )
            ->first();

        if ($record === null) {
            return ['found' => false, 'value' => null];
        }

        // Pennant stores values as JSON
        $value = json_decode($record->value, associative: true);

        return ['found' => true, 'value' => $value];
    }

    /**
     * Serialize a context to match Pennant's scope format.
     *
     * Pennant typically uses "Model|id" format for scopes.
     * For example: "App\Models\User|123"
     *
     * @param  TogglContext $context Context to serialize
     * @return string|null  Serialized scope string, or null for global features
     */
    private function serializeContextForPennant(TogglContext $context): ?string
    {
        // Global context (null id) maps to null scope
        if ($context->id === null) {
            return null;
        }

        // Use type and id to create scope string
        // Format: "Type|id"
        return $context->type.'|'.$context->id;
    }

    /**
     * Get the database connection for Pennant queries.
     *
     * @return ConnectionInterface Database connection
     */
    private function getConnection(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }
}
