<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Migrators\YLSIdeasMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Exceptions\ExpectedExceptionNotThrownException;

uses(RefreshDatabase::class);

/**
 * Set up the test database schema for YLSIdeas features table.
 *
 * Creates a YLSIdeas-compatible features table with a unique feature name column
 * and a nullable active_at timestamp that determines feature activation status.
 * The presence of an active_at timestamp indicates an active feature.
 */
beforeEach(function (): void {
    // Legacy table from YLSIdeas package - uses auto-increment ID
    Schema::create('ylsideas_features', function ($table): void {
        $table->id();
        $table->string('feature')->unique();
        $table->timestamp('active_at')->nullable();
        $table->timestamp('migrated_at')->nullable();
        $table->timestamps();
    });
});

/**
 * Test suite for YLSIdeasMigrator functionality.
 *
 * Validates migration of feature flags from the YLSIdeas feature flag package's
 * database format to Toggl's driver system. Tests cover timestamp-based activation
 * states, error handling, and custom table/field name configuration. Features
 * are considered active when active_at is non-null, inactive when null.
 */
describe('YLSIdeasMigrator', function (): void {
    describe('Happy Path', function (): void {
        test('migrates active features from YLSIdeas database to Toggl driver', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', false);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(2);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates all inactive features', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->times(3)->with(Mockery::type('string'), false);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(3);
            expect($stats['contexts'])->toBe(3);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates all active features', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->times(3)->with(Mockery::type('string'), true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(3);
            expect($stats['contexts'])->toBe(3);
            expect($stats['errors'])->toBeEmpty();
        });

        test('filters records by updated_at when since is provided', function (): void {
            // Arrange
            $cutoff = now()->subHour();

            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => $cutoff->copy()->subDay()],
                ['feature' => 'feature-2', 'active_at' => null, 'created_at' => now(), 'updated_at' => $cutoff->copy()->addMinute()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', false);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
                since: $cutoff,
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });
    });

    describe('Sad Path', function (): void {
        test('handles empty database gracefully', function (): void {
            // Arrange
            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->never();

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(0);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('records errors for failed feature migrations', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true)->andThrow(
                new RuntimeException('Database error'),
            );
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('feature-1');
            expect($stats['errors'][0])->toContain('Database error');
        });

        test('continues migration after encountering errors', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-2', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['feature' => 'feature-3', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', true)->andThrow(
                new RuntimeException('Error'),
            );
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-3', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(2);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toHaveCount(1);
        });

        test('throws exception and records error when database fetch fails', function (): void {
            DB::beginTransaction();

            try {
                // Arrange
                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('setForAllContexts')->never();

                $migrator = new YLSIdeasMigrator(
                    $driver,
                    'nonexistent_table',
                );

                // Act & Assert
                try {
                    $migrator->migrate();

                    throw ExpectedExceptionNotThrownException::inTest();
                } catch (Throwable $throwable) {
                    expect($throwable)->toBeInstanceOf(Throwable::class);
                }

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(0);
                expect($stats['contexts'])->toBe(0);
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Migration failed:');
            } finally {
                DB::rollBack();
            }
        });
    });

    describe('Edge Cases', function (): void {
        test('handles custom table name', function (): void {
            // Arrange
            Schema::create('custom_ylsideas_table', function ($table): void {
                $table->id();
                $table->string('feature')->unique();
                $table->timestamp('active_at')->nullable();
                $table->timestamps();
            });

            DB::table('custom_ylsideas_table')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'custom_ylsideas_table',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });

        test('handles custom field name', function (): void {
            // Arrange
            Schema::create('custom_field_table', function ($table): void {
                $table->id();
                $table->string('feature')->unique();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamps();
            });

            DB::table('custom_field_table')->insert([
                ['feature' => 'feature-1', 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'custom_field_table',
                'enabled_at',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });

        test('handles custom database connection', function (): void {
            // Arrange
            DB::table('ylsideas_features')->insert([
                ['feature' => 'feature-1', 'active_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

            $migrator = new YLSIdeasMigrator(
                $driver,
                'ylsideas_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe(1);
        });
    });

    describe('Migration Tracking', function (): void {
        describe('Happy Path', function (): void {
            test('filters records with migrated_at when tracking enabled', function (): void {
                // Arrange
                $migratedTimestamp = now()->subDay();
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'already-migrated', 'active_at' => now(), 'migrated_at' => $migratedTimestamp, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'not-migrated-1', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'not-migrated-2', 'active_at' => null, 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Should only process records with NULL migrated_at
                $driver->shouldReceive('setForAllContexts')->once()->with('not-migrated-1', true);
                $driver->shouldReceive('setForAllContexts')->once()->with('not-migrated-2', false);
                // Should NOT process already-migrated
                $driver->shouldReceive('setForAllContexts')->never()->with('already-migrated', Mockery::any());

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(2);
                expect($stats['contexts'])->toBe(2);
                expect($stats['errors'])->toBeEmpty();
            });

            test('updates migrated_at timestamp after successful migration', function (): void {
                // Arrange
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'feature-1', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $record = DB::table('ylsideas_features')->where('feature', 'feature-1')->first();
                expect($record->migrated_at)->not->toBeNull();
                // Verify timestamp is recent (within last minute)
                expect(now()->diffInSeconds($record->migrated_at))->toBeLessThan(60);

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(1);
                expect($stats['errors'])->toBeEmpty();
            });

            test('processes all records when tracking disabled', function (): void {
                // Arrange
                $migratedTimestamp = now()->subDay();
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'already-migrated', 'active_at' => now(), 'migrated_at' => $migratedTimestamp, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'not-migrated', 'active_at' => null, 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Should process ALL records regardless of migrated_at
                $driver->shouldReceive('setForAllContexts')->once()->with('already-migrated', true);
                $driver->shouldReceive('setForAllContexts')->once()->with('not-migrated', false);

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: false,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(2);
                expect($stats['contexts'])->toBe(2);
                expect($stats['errors'])->toBeEmpty();

                // Verify migrated_at was NOT updated (tracking disabled)
                $alreadyMigrated = DB::table('ylsideas_features')->where('feature', 'already-migrated')->first();
                expect($alreadyMigrated->migrated_at)->toEqual($migratedTimestamp);
                $notMigrated = DB::table('ylsideas_features')->where('feature', 'not-migrated')->first();
                expect($notMigrated->migrated_at)->toBeNull();
            });

            test('second migration run only processes new records', function (): void {
                // Arrange - First migration
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'feature-1', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'feature-2', 'active_at' => null, 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', false);

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act - First migration
                $migrator->migrate();

                // Assert - First migration
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(2);
                expect($stats['contexts'])->toBe(2);
                expect($stats['errors'])->toBeEmpty();

                // Arrange - Add new records
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'feature-3', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'feature-4', 'active_at' => null, 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                // Create new migrator with fresh driver for second run
                $driver2 = Mockery::mock(Driver::class);
                // Should only process NEW records (feature-3 and feature-4)
                $driver2->shouldReceive('setForAllContexts')->once()->with('feature-3', true);
                $driver2->shouldReceive('setForAllContexts')->once()->with('feature-4', false);
                // Should NOT process already-migrated records (feature-1 and feature-2)
                $driver2->shouldReceive('setForAllContexts')->never()->with('feature-1', Mockery::any());
                $driver2->shouldReceive('setForAllContexts')->never()->with('feature-2', Mockery::any());

                $migrator2 = new YLSIdeasMigrator(
                    driver: $driver2,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act - Second migration
                $migrator2->migrate();

                // Assert - Second migration
                $stats2 = $migrator2->getStatistics();
                expect($stats2['features'])->toBe(2);
                expect($stats2['contexts'])->toBe(2);
                expect($stats2['errors'])->toBeEmpty();
            });
        });

        describe('Sad Path', function (): void {
            test('logs error when timestamp update fails but continues migration', function (): void {
                // Arrange - Create table without ID column (will cause update to fail)
                Schema::drop('ylsideas_features');
                Schema::create('ylsideas_features', function ($table): void {
                    // No id() column - this will cause markRecordAsMigrated to report missing ID error
                    $table->string('feature')->unique();
                    $table->timestamp('active_at')->nullable();
                    $table->timestamp('migrated_at')->nullable();
                    $table->timestamps();
                });

                DB::table('ylsideas_features')->insert([
                    ['feature' => 'feature-1', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'feature-2', 'active_at' => null, 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Both records should still be processed despite timestamp update failures
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-2', false);

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(2);
                expect($stats['contexts'])->toBe(2);
                expect($stats['errors'])->toHaveCount(2);
                expect($stats['errors'][0])->toContain('Cannot mark record as migrated: missing ID');
                expect($stats['errors'][1])->toContain('Cannot mark record as migrated: missing ID');
            });

            test('logs error when record has no id', function (): void {
                // Arrange - Create table without id column
                Schema::drop('ylsideas_features');
                Schema::create('ylsideas_features', function ($table): void {
                    $table->string('feature')->unique();
                    $table->timestamp('active_at')->nullable();
                    $table->timestamp('migrated_at')->nullable();
                    $table->timestamps();
                });

                DB::table('ylsideas_features')->insert([
                    ['feature' => 'feature-1', 'active_at' => now(), 'migrated_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('setForAllContexts')->once()->with('feature-1', true);

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(1);
                expect($stats['contexts'])->toBe(1);
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Cannot mark record as migrated: missing ID');
            });
        });

        describe('Edge Cases', function (): void {
            test('handles custom tracking column name', function (): void {
                // Arrange - Create table with custom tracking column
                Schema::drop('ylsideas_features');
                Schema::create('ylsideas_features', function ($table): void {
                    $table->id();
                    $table->string('feature')->unique();
                    $table->timestamp('active_at')->nullable();
                    $table->timestamp('last_migrated')->nullable();
                    $table->timestamps();
                });

                $alreadyMigratedTimestamp = now()->subDay();
                DB::table('ylsideas_features')->insert([
                    ['feature' => 'already-migrated', 'active_at' => now(), 'last_migrated' => $alreadyMigratedTimestamp, 'created_at' => now(), 'updated_at' => now()],
                    ['feature' => 'not-migrated', 'active_at' => null, 'last_migrated' => null, 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Should only process records with NULL last_migrated
                $driver->shouldReceive('setForAllContexts')->once()->with('not-migrated', false);
                // Should NOT process already-migrated
                $driver->shouldReceive('setForAllContexts')->never()->with('already-migrated', Mockery::any());

                $migrator = new YLSIdeasMigrator(
                    driver: $driver,
                    table: 'ylsideas_features',
                    migrationTracking: true,
                    trackingColumn: 'last_migrated',
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe(1);
                expect($stats['contexts'])->toBe(1);
                expect($stats['errors'])->toBeEmpty();

                // Verify custom column was updated
                $notMigrated = DB::table('ylsideas_features')->where('feature', 'not-migrated')->first();
                expect($notMigrated->last_migrated)->not->toBeNull();
                expect(now()->diffInSeconds($notMigrated->last_migrated))->toBeLessThan(60);

                // Verify already-migrated timestamp unchanged
                $alreadyMigrated = DB::table('ylsideas_features')->where('feature', 'already-migrated')->first();
                expect($alreadyMigrated->last_migrated)->toEqual($alreadyMigratedTimestamp);
            });
        });
    });
});
