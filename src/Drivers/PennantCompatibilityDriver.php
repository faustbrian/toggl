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

use function array_values;
use function assert;
use function is_int;
use function is_string;
use function json_decode;
use function property_exists;

/**
 * Laravel Pennant compatibility driver for gradual migration.
 *
 * This driver wraps the standard DatabaseDriver and intercepts read operations
 * to check Toggl's features table first, falling back to Pennant for unmigrated records.
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
 * @psalm-immutable
 */
final readonly class PennantCompatibilityDriver implements CanListStoredFeatures, Driver
{
    /**
     * Create a new Pennant compatibility driver instance.
     *
     * @param DatabaseDriver $togglDriver    The underlying Toggl database driver for writes
     * @param string         $pennantTable   The Pennant features table name
     * @param null|string    $connectionName Database connection name for Pennant table
     */
    public function __construct(
        private DatabaseDriver $togglDriver,
        private string $pennantTable = 'features',
        private ?string $connectionName = null,
    ) {}

    public function define(string $feature, mixed $resolver = null): mixed
    {
        return $this->togglDriver->define($feature, $resolver);
    }

    /**
     * @return array<int, string>
     */
    public function defined(): array
    {
        return array_values($this->togglDriver->defined());
    }

    /**
     * @return array<int, string>
     */
    public function stored(): array
    {
        return array_values($this->togglDriver->stored());
    }

    /**
     * Get multiple feature flag values, checking Toggl first.
     *
     * For each feature/context pair:
     * 1. Check Toggl's features table first (without triggering resolver)
     * 2. If not found in Toggl, check Pennant's features table
     * 3. If not in either, let Toggl resolve and persist
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
     * Get a feature flag's value, checking Toggl first.
     *
     * Read priority (inverted for migration):
     * 1. Check Toggl's features table (without triggering resolver)
     * 2. If not found in Toggl, check Laravel Pennant's features table
     * 3. If not in either, let Toggl resolve and persist
     *
     * @param  string       $feature Feature name
     * @param  TogglContext $context Context for the feature
     * @return FeatureValue Feature value
     */
    public function get(string $feature, TogglContext $context): FeatureValue
    {
        // Try Toggl first (without triggering resolver)
        $togglValue = $this->togglDriver->retrieveOnly($feature, $context);

        // If Toggl has a defined value (even false/inactive), use it
        if ($togglValue instanceof FeatureValue) {
            return $togglValue;
        }

        // Toggl has no value - check Pennant
        $pennantResult = $this->getFromPennant($feature, $context);

        if ($pennantResult['found'] === true) {
            return FeatureValue::from($pennantResult['value']);
        }

        // Neither has value - let Toggl resolve and persist
        $resolvedValue = $this->togglDriver->get($feature, $context);

        return FeatureValue::from($resolvedValue);
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
     * @param  string                                $feature Feature name
     * @param  TogglContext                          $context Context to check
     * @return array{found: bool, value: null|mixed} Result array with 'found' flag and value
     */
    private function getFromPennant(string $feature, TogglContext $context): array
    {
        $connection = $this->getConnection();
        $scope = $this->serializeContextForPennant($context);

        $query = $connection->table($this->pennantTable)
            ->where('name', $feature);

        if (null === $scope) {
            $query->whereNull('scope');
        } else {
            $query->where('scope', $scope);
        }

        $record = $query->first();

        if ($record === null) {
            return ['found' => false, 'value' => null];
        }

        // Pennant stores values as JSON
        if (!property_exists($record, 'value') || !is_string($record->value)) {
            return ['found' => false, 'value' => null];
        }

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
     * @return null|string  Serialized scope string, or null for global features
     */
    private function serializeContextForPennant(TogglContext $context): ?string
    {
        // Global context (null id) maps to null scope
        if ($context->id === null) {
            return null;
        }

        $id = $context->id;

        assert(is_int($id) || is_string($id));

        // Use type and id to create scope string
        // Format: "Type|id"
        return $context->type.'|'.$id;
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
