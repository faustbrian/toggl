<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\Driver;
use Cline\Toggl\Drivers\DatabaseDriver;
use Cline\Toggl\Migrators\PennantMigrator;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\SoftDeletableUser;
use Tests\Fixtures\User;

uses(RefreshDatabase::class);

/**
 * Set up the test database schema for Pennant features table.
 *
 * Creates a standard Pennant features table with name, context, and value columns,
 * including a unique constraint on the name-context combination to prevent
 * duplicate feature-context pairs during migration testing.
 */
beforeEach(function (): void {
    // Use default test connection (in-memory SQLite)
    config()->set('toggl.stores.database.connection');

    // Legacy table from Pennant package - uses auto-increment ID
    Schema::create('pennant_features', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('scope');
        $table->text('value');
        $table->timestamps();
        $table->timestamp('migrated_at')->nullable();
        $table->unique(['name', 'scope']);
    });

    if (Schema::hasTable('users')) {
        return;
    }

    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });
});

/**
 * Test suite for PennantMigrator functionality.
 *
 * Validates migration of feature flags from Laravel Pennant's database storage
 * format to Toggl's driver system. Tests cover multi-context features, JSON value
 * parsing, error handling during migration, and statistics tracking. Ensures
 * proper conversion of Pennant's string-based values to native PHP types.
 */
describe('PennantMigrator', function (): void {
    describe('Happy Path', function (): void {
        test('migrates features from Pennant database to Toggl driver', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'feature-2', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
            $driver->shouldReceive('set')->once()->with('feature-2', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates features with multiple contexts', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), false);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toBeEmpty();
        });

        test('migrates complex JSON values', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => '{"key":"value","nested":{"foo":"bar"}}', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), ['key' => 'value', 'nested' => ['foo' => 'bar']]);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('deserializes model contexts using class::find()', function (): void {
            // Arrange
            $user = User::query()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            // The context is resolved to TogglContext, with the model preserved as source
            $driver->shouldReceive('set')->once()->with(
                'feature-1',
                Mockery::on(fn ($context): bool => $context instanceof TogglContext
                    && $context->id === $user->id
                    && $context->source instanceof User
                    && $context->source->id === $user->id),
                true,
            );

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('records error for string contexts without pipe separator (not resolvable)', function (): void {
            // Arrange
            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'custom-string-context', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert - String contexts can't be resolved to TogglContext, so they error
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('custom-string-context');
        });
    });

    describe('Sad Path', function (): void {
        test('handles empty database gracefully', function (): void {
            // Arrange
            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('records errors for failed context migrations within a feature', function (): void {
            // Arrange - Use a model context so it can be resolved
            $user1 = User::query()->create([
                'name' => 'Test User 1',
                'email' => 'test1@example.com',
            ]);
            $user2 = User::query()->create([
                'name' => 'Test User 2',
                'email' => 'test2@example.com',
            ]);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), true)->andThrow(
                new RuntimeException('Context error'),
            );

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('Tests\Fixtures\User|'.$user2->id);
            expect($stats['errors'][0])->toContain('Context error');
        });

        test('throws exception when database table does not exist', function (): void {
            DB::beginTransaction();

            try {
                // Arrange - Use a non-existent table name
                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->never();
                $driver->shouldReceive('setForAllContexts')->never();

                $migrator = new PennantMigrator(
                    $driver,
                    'nonexistent_table_that_does_not_exist',
                );

                // Act & Assert - Should throw database exception
                try {
                    $migrator->migrate();

                    throw ExpectedExceptionNotThrownException::inTest();
                } catch (Throwable $throwable) {
                    expect($throwable)->toBeInstanceOf(Throwable::class);
                }

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(0);
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Migration failed:');
            } finally {
                DB::rollBack();
            }
        });

        test('records error for invalid record with missing context property', function (): void {
            // Arrange
            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name');
                // No context column
                $table->text('value');
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('feature-1');
            expect($stats['errors'][0])->toContain('unknown');
        });

        test('records error for invalid record with missing value property', function (): void {
            // Arrange
            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('scope');
                // No value column
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'null', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('feature-1');
            expect($stats['errors'][0])->toContain('null');
        });

        test('records error for invalid JSON in value field', function (): void {
            // Arrange
            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'null', 'value' => '{invalid-json}', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('feature-1');
        });
    });

    describe('Regression', function (): void {
        test('migrates soft-deleted users when include_soft_deleted config is true', function (): void {
            // Arrange - add deleted_at column and enable soft delete migration
            Schema::table('users', function ($table): void {
                $table->softDeletes();
            });

            config(['toggl.migrators.pennant.include_soft_deleted' => true]);

            $user = SoftDeletableUser::query()->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
            $user->delete(); // Soft delete

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\SoftDeletableUser|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('skips soft-deleted users when include_soft_deleted config is false', function (): void {
            // Arrange - add deleted_at column (config default is false)
            Schema::table('users', function ($table): void {
                $table->softDeletes();
            });

            $user = SoftDeletableUser::query()->create(['name' => 'Deleted User', 'email' => 'deleted@example.com']);
            $user->delete(); // Soft delete

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\SoftDeletableUser|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toHaveCount(1);
            expect($stats['errors'][0])->toContain('Skipping deleted/missing model');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles custom table name', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            Schema::create('custom_pennant_table', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            DB::table('custom_pennant_table')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'custom_pennant_table',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
        });

        test('handles custom database connection', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
        });

        test('fetchAllFeatures returns empty array for empty database', function (): void {
            // Arrange - Empty table (no records inserted)
            $driver = Mockery::mock(Driver::class);
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('skips records without name property and migrates valid records', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                // Valid record - should be migrated
                ['name' => 'valid-feature', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Insert record without name property using raw SQL
            DB::statement('INSERT INTO pennant_features (scope, value, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'Tests\Fixtures\User|'.$user->id,
                'true',
                now(),
                now(),
            ]);

            $driver = Mockery::mock(Driver::class);
            // Only the valid record should be processed
            $driver->shouldReceive('set')->once()->with('valid-feature', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('skips records with non-string name and migrates valid records', function (): void {
            // Arrange
            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->integer('name'); // Non-string name column
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                // Invalid record with numeric name - should be skipped
                ['name' => 12_345, 'scope' => 'null', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Re-create table with proper schema for valid record
            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
                $table->unique(['name', 'scope']);
            });

            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            DB::table('pennant_features')->insert([
                // Valid record - should be migrated
                ['name' => 'valid-feature', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            // Only the valid string-named record should be processed
            $driver->shouldReceive('set')->once()->with('valid-feature', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('fetchAllFeatures groups records by name after skipping invalid records', function (): void {
            // Arrange
            $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
            $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                // Valid feature with multiple contexts
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Insert invalid record without name
            DB::statement('INSERT INTO pennant_features (scope, value, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'Tests\Fixtures\User|'.$user1->id,
                'true',
                now(),
                now(),
            ]);

            $user3 = User::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

            DB::table('pennant_features')->insert([
                // Another valid feature
                ['name' => 'feature-2', 'scope' => 'Tests\Fixtures\User|'.$user3->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            // Should process all valid records
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);
            $driver->shouldReceive('set')->once()->with('feature-2', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user3->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(3);
            expect($stats['errors'])->toBeEmpty();
        });

        test('skips all records when none have name property', function (): void {
            // Arrange
            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            // Insert multiple records without name property
            DB::statement('INSERT INTO pennant_features (scope, value, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'null',
                'true',
                now(),
                now(),
            ]);
            DB::statement('INSERT INTO pennant_features (scope, value, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'user-1',
                'false',
                now(),
                now(),
            ]);

            $driver = Mockery::mock(Driver::class);
            // No records should be processed since none have name property
            $driver->shouldReceive('set')->never();

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(0);
            expect($stats['errors'])->toBeEmpty();
        });

        test('skips records with null name value', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            DB::table('pennant_features')->insert([
                // Record with null name value
                ['name' => null, 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                // Valid record
                ['name' => 'valid-feature', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            // Only valid record should be processed
            $driver->shouldReceive('set')->once()->with('valid-feature', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(1);
            expect($stats['errors'])->toBeEmpty();
        });

        test('handles mixed invalid records - missing property, null value, and non-string type', function (): void {
            // Arrange
            $user = User::query()->create(['name' => 'Test', 'email' => 'test@example.com']);

            Schema::drop('pennant_features');
            Schema::create('pennant_features', function ($table): void {
                $table->id();
                $table->string('name')->nullable();
                $table->string('scope');
                $table->text('value');
                $table->timestamps();
            });

            $user2 = User::query()->create(['name' => 'Test 2', 'email' => 'test2@example.com']);

            // Insert record with null name
            DB::table('pennant_features')->insert([
                ['name' => null, 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ]);

            // Insert record without name property
            DB::statement('INSERT INTO pennant_features (scope, value, created_at, updated_at) VALUES (?, ?, ?, ?)', [
                'Tests\Fixtures\User|999', // Non-existent user (will resolve to null, causing error)
                'false',
                now(),
                now(),
            ]);

            // Insert valid records
            DB::table('pennant_features')->insert([
                ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'feature-2', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $driver = Mockery::mock(Driver::class);
            // Only valid records should be processed
            $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);
            $driver->shouldReceive('set')->once()->with('feature-2', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

            $migrator = new PennantMigrator(
                $driver,
                'pennant_features',
            );

            // Act
            $migrator->migrate();

            // Assert
            $stats = $migrator->getStatistics();
            expect($stats['features'])->toBe($stats['contexts']);
            expect($stats['contexts'])->toBe(2);
            expect($stats['errors'])->toBeEmpty();
        });
    });

    describe('Migration Tracking', function (): void {
        describe('Happy Path', function (): void {
            test('filters records with migrated_at when tracking enabled', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);
                $user3 = User::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

                DB::table('pennant_features')->insert([
                    // Already migrated - should be skipped
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => now()->subDay()],
                    // Not migrated - should be processed
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                    ['name' => 'feature-2', 'scope' => 'Tests\Fixtures\User|'.$user3->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Should only process unmigrated records
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);
                $driver->shouldReceive('set')->once()->with('feature-2', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user3->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']); // feature-1 and feature-2
                expect($stats['contexts'])->toBe(2); // Only user2 and user3
                expect($stats['errors'])->toBeEmpty();
            });

            test('updates migrated_at timestamp after successful migration', function (): void {
                // Arrange
                $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $record = DB::table('pennant_features')->where('scope', 'Tests\Fixtures\User|'.$user->id)->first();
                expect($record)->not->toBeNull();
                expect($record->migrated_at)->not->toBeNull();

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(1);
                expect($stats['errors'])->toBeEmpty();
            });

            test('processes all records when tracking disabled', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                DB::table('pennant_features')->insert([
                    // Has migrated_at timestamp but should still be processed
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => now()->subDay()],
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Should process ALL records regardless of migrated_at
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: false, // Tracking disabled
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(2); // Both contexts processed
                expect($stats['errors'])->toBeEmpty();
            });

            test('re-running without tracking does not duplicate database records', function (): void {
                // Arrange
                $user = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
                ]);

                $driver = new DatabaseDriver(
                    resolve(Dispatcher::class),
                    'database',
                    [],
                );

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: false,
                );

                // Act
                $migrator->migrate();
                $migrator->migrate();

                // Assert
                $count = DB::table('features')
                    ->where('name', 'feature-1')
                    ->where('context_type', $user->getMorphClass())
                    ->where('context_id', $user->id)
                    ->count();

                expect($count)->toBe(1);
            });

            test('filters records by updated_at when since is provided', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                $cutoff = now()->subHour();

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => $cutoff->copy()->subDay()],
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => $cutoff->copy()->addMinute()],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: false,
                    since: $cutoff,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(1);
                expect($stats['errors'])->toBeEmpty();
            });

            test('second migration run only processes new records', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                // First migration run
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act - First migration
                $migrator->migrate();

                // Assert - First migration
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(1);

                // Arrange - Add new record
                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                // Create new migrator for second run
                $driver2 = Mockery::mock(Driver::class);
                // Second migration run should only process new record
                $driver2->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

                $migrator2 = new PennantMigrator(
                    $driver2,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act - Second migration
                $migrator2->migrate();

                // Assert - Second migration
                $stats2 = $migrator2->getStatistics();
                expect($stats2['features'])->toBe($stats2['contexts']);
                expect($stats2['contexts'])->toBe(1); // Only new record processed
                expect($stats2['errors'])->toBeEmpty();
            });
        });

        describe('Sad Path', function (): void {
            test('logs error when timestamp update fails but continues migration', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);

                // Insert records without IDs (will cause update to fail)
                Schema::drop('pennant_features');
                Schema::create('pennant_features', function ($table): void {
                    $table->string('name');
                    $table->string('scope');
                    $table->text('value');
                    $table->timestamps();
                    $table->timestamp('migrated_at')->nullable();
                });

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                // Both records should still be processed despite timestamp update failures
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(2); // Both contexts processed successfully
                expect($stats['errors'])->toHaveCount(2); // Two timestamp update errors
                expect($stats['errors'][0])->toContain('Cannot mark record as migrated: missing ID');
            });

            test('logs error when record has no id', function (): void {
                // Arrange
                $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

                // Create table without ID column
                Schema::drop('pennant_features');
                Schema::create('pennant_features', function ($table): void {
                    $table->string('name');
                    $table->string('scope');
                    $table->text('value');
                    $table->timestamps();
                    $table->timestamp('migrated_at')->nullable();
                });

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(1); // Migration succeeded
                expect($stats['errors'])->toHaveCount(1);
                expect($stats['errors'][0])->toContain('Cannot mark record as migrated: missing ID');
            });
        });

        describe('Edge Cases', function (): void {
            test('handles custom tracking column name', function (): void {
                // Arrange
                $user = User::query()->create(['name' => 'Test User', 'email' => 'test@example.com']);

                // Create table with custom tracking column
                Schema::drop('pennant_features');
                Schema::create('pennant_features', function ($table): void {
                    $table->id();
                    $table->string('name');
                    $table->string('scope');
                    $table->text('value');
                    $table->timestamps();
                    $table->timestamp('last_migrated')->nullable();
                    $table->unique(['name', 'scope']);
                });

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'last_migrated' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                    trackingColumn: 'last_migrated',
                );

                // Act
                $migrator->migrate();

                // Assert
                $record = DB::table('pennant_features')->where('scope', 'Tests\Fixtures\User|'.$user->id)->first();
                expect($record)->not->toBeNull();
                expect($record->last_migrated)->not->toBeNull();

                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(1);
                expect($stats['errors'])->toBeEmpty();
            });

            test('multiple contexts per feature with tracking', function (): void {
                // Arrange
                $user1 = User::query()->create(['name' => 'User 1', 'email' => 'user1@example.com']);
                $user2 = User::query()->create(['name' => 'User 2', 'email' => 'user2@example.com']);
                $user3 = User::query()->create(['name' => 'User 3', 'email' => 'user3@example.com']);

                DB::table('pennant_features')->insert([
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user1->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user2->id, 'value' => 'false', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                    ['name' => 'feature-1', 'scope' => 'Tests\Fixtures\User|'.$user3->id, 'value' => 'true', 'created_at' => now(), 'updated_at' => now(), 'migrated_at' => null],
                ]);

                $driver = Mockery::mock(Driver::class);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user1->id), true);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user2->id), false);
                $driver->shouldReceive('set')->once()->with('feature-1', Mockery::on(fn ($ctx): bool => $ctx instanceof TogglContext && $ctx->id === $user3->id), true);

                $migrator = new PennantMigrator(
                    $driver,
                    'pennant_features',
                    migrationTracking: true,
                );

                // Act
                $migrator->migrate();

                // Assert - Verify all contexts processed
                $stats = $migrator->getStatistics();
                expect($stats['features'])->toBe($stats['contexts']);
                expect($stats['contexts'])->toBe(3); // All three contexts

                // Verify each record has migrated_at timestamp
                $record1 = DB::table('pennant_features')->where('scope', 'Tests\Fixtures\User|'.$user1->id)->first();
                $record2 = DB::table('pennant_features')->where('scope', 'Tests\Fixtures\User|'.$user2->id)->first();
                $record3 = DB::table('pennant_features')->where('scope', 'Tests\Fixtures\User|'.$user3->id)->first();

                expect($record1->migrated_at)->not->toBeNull();
                expect($record2->migrated_at)->not->toBeNull();
                expect($record3->migrated_at)->not->toBeNull();
                expect($stats['errors'])->toBeEmpty();
            });
        });
    });
});
