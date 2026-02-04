<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Drivers\DatabaseDriver;
use Cline\Toggl\Drivers\PennantCompatibilityDriver;
use Cline\Toggl\Toggl;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Model\LegacyUser;
use Tests\Fixtures\User;

beforeEach(function (): void {
    // Set database driver as default for all tests
    Config::set('toggl.default', 'database');

    // Create Pennant features table
    Schema::create('pennant_features', function ($table): void {
        $table->string('name');
        $table->string('scope')->nullable();
        $table->text('value');
        $table->timestamps();

        $table->unique(['name', 'scope']);
    });
});

afterEach(function (): void {
    Schema::dropIfExists('pennant_features');
});

describe('Pennant Compatibility', function (): void {
    test('reads from Toggl table first when compatibility enabled', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 1]);

        // Insert a feature into Pennant table for this user
        DB::table('pennant_features')->insert([
            'name' => 'test-feature',
            'scope' => User::class.'|1',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify the record was inserted
        $record = DB::table('pennant_features')->where('name', 'test-feature')->first();
        expect($record)->not->toBeNull();
        expect($record->value)->toBe(json_encode(true));

        // Verify driver is compatibility driver
        $driver = Toggl::driver()->getDriver();
        expect($driver)->toBeInstanceOf(PennantCompatibilityDriver::class);

        // Should read from Pennant (since not in Toggl)
        expect(Toggl::for($user)->active('test-feature'))->toBeTrue();
    });

    test('falls back to Toggl when feature not in Pennant', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 2]);

        // Activate feature in Toggl
        Toggl::activate('toggl-feature')->for($user);

        // Should fall back to Toggl (not in Pennant)
        expect(Toggl::for($user)->active('toggl-feature'))->toBeTrue();
    });

    test('writes only to Toggl table', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 3]);

        // Activate feature
        Toggl::activate('new-feature')->for($user);

        // Should be in Toggl table
        expect(DB::table('features')->where('name', 'new-feature')->exists())->toBeTrue();

        // Should NOT be in Pennant table
        expect(DB::table('pennant_features')->where('name', 'new-feature')->exists())->toBeFalse();
    });

    test('respects context when reading from Pennant', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 123]);

        // Insert feature for specific user in Pennant
        DB::table('pennant_features')->insert([
            'name' => 'user-feature',
            'scope' => $user::class.'|123',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Should read from Pennant for this user
        expect(Toggl::for($user)->active('user-feature'))->toBeTrue();
    });

    test('uses DatabaseDriver when compatibility disabled', function (): void {
        // Disable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', false);

        // Rebuild the driver
        Toggl::forgetDriver();

        // Should use plain DatabaseDriver
        $driver = Toggl::driver()->getDriver();
        expect($driver)->toBeInstanceOf(DatabaseDriver::class);
    });

    test('uses PennantCompatibilityDriver when compatibility enabled', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Rebuild the driver
        Toggl::forgetDriver();

        // Should use compatibility driver
        $driver = Toggl::driver()->getDriver();
        expect($driver)->toBeInstanceOf(PennantCompatibilityDriver::class);
    });

    test('prioritizes Toggl value over Pennant value', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 10]);

        // Insert feature into Pennant table with TRUE
        DB::table('pennant_features')->insert([
            'name' => 'priority-feature',
            'scope' => User::class.'|10',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert feature into Toggl table with FALSE
        Toggl::deactivate('priority-feature')->for($user);

        // Verify both records exist
        expect(DB::table('pennant_features')->where('name', 'priority-feature')->exists())->toBeTrue();
        expect(DB::table('features')->where('name', 'priority-feature')->exists())->toBeTrue();

        // Should return Toggl's value (false), NOT Pennant's (true)
        expect(Toggl::for($user)->active('priority-feature'))->toBeFalse();
    });

    test('falls back to Pennant when Toggl has no value', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 11]);

        // Insert feature ONLY in Pennant table
        DB::table('pennant_features')->insert([
            'name' => 'pennant-only-feature',
            'scope' => User::class.'|11',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify NO record in Toggl
        expect(DB::table('features')->where('name', 'pennant-only-feature')->exists())->toBeFalse();

        // Should fall back to Pennant's value
        expect(Toggl::for($user)->active('pennant-only-feature'))->toBeTrue();
    });

    test('matches legacy Pennant scope when morph map is enforced', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Enforce morph map to simulate application aliasing
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 78467]);

        // Insert legacy Pennant record with fully-qualified class scope
        DB::table('pennant_features')->insert([
            'name' => 'legacy-scope-feature',
            'scope' => User::class.'|78467',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Should resolve via compatibility driver despite morph map aliasing
        expect(Toggl::for($user)->active('legacy-scope-feature'))->toBeTrue();
    });

    test('matches legacy namespace swap with legacy numeric id', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a legacy user with ULID primary key and numeric id attribute
        $legacy = LegacyUser::create([
            'ulid' => '01hhe1z24gg2dnv97mm0a2zb22',
            'id' => 78467,
            'name' => 'Legacy User',
        ]);

        // Insert Pennant record using legacy namespace and numeric id
        DB::table('pennant_features')->insert([
            'name' => 'legacy-namespace-feature',
            'scope' => 'Tests\\Fixtures\\Models\\LegacyUser|78467',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(Toggl::for($legacy)->active('legacy-namespace-feature'))->toBeTrue();
    });

    test('resolves morph alias to FQCN scope', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        Relation::enforceMorphMap([
            'legacy_user' => LegacyUser::class,
        ]);

        // Force driver recreation
        Toggl::forgetDriver();

        $legacy = LegacyUser::create([
            'ulid' => '01hhe1z24gg2dnv97mm0a2zb23',
            'id' => 9001,
            'name' => 'Legacy User 2',
        ]);

        DB::table('pennant_features')->insert([
            'name' => 'morph-alias-feature',
            'scope' => LegacyUser::class.'|'.$legacy->getKey(),
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(Toggl::for($legacy)->active('morph-alias-feature'))->toBeTrue();
    });

    test('toggl explicit false takes priority over pennant true', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 12]);

        // Insert TRUE in Pennant
        DB::table('pennant_features')->insert([
            'name' => 'explicit-false-feature',
            'scope' => User::class.'|12',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Set explicit FALSE in Toggl
        Toggl::deactivate('explicit-false-feature')->for($user);

        // Toggl's explicit false should take priority
        expect(Toggl::for($user)->active('explicit-false-feature'))->toBeFalse();
    });

    test('triggers toggl resolver when neither has value', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 13]);

        // Define a feature resolver that returns true
        Toggl::define('resolver-feature', fn (): true => true);

        // Verify neither Pennant nor Toggl have this feature yet
        expect(DB::table('pennant_features')->where('name', 'resolver-feature')->exists())->toBeFalse();
        expect(DB::table('features')->where('name', 'resolver-feature')->exists())->toBeFalse();

        // Should trigger resolver and return true
        expect(Toggl::for($user)->active('resolver-feature'))->toBeTrue();

        // Should now be persisted in Toggl
        expect(DB::table('features')->where('name', 'resolver-feature')->exists())->toBeTrue();

        // Should still NOT be in Pennant
        expect(DB::table('pennant_features')->where('name', 'resolver-feature')->exists())->toBeFalse();
    });

    test('expired toggl record returns false and is deleted from storage', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');
        Config::set('toggl.default', 'database');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 20]);

        // First, verify the feature returns false by default (no resolver)
        expect(Toggl::for($user)->active('nonexistent-feature'))->toBeFalse();

        // Create record in Toggl using the API
        Toggl::activate('expired-feature')->for($user);

        // Verify it's active before expiration
        expect(Toggl::for($user)->active('expired-feature'))->toBeTrue();

        // Manually set expiration time in the past for ALL matching records
        $updated = DB::table('features')
            ->where('name', 'expired-feature')
            ->update(['expires_at' => now()->subDay()]);
        expect($updated)->toBeGreaterThan(0);

        // Verify the expired record exists after update
        $togglRecord = DB::table('features')
            ->where('name', 'expired-feature')
            ->first();
        expect($togglRecord)->not->toBeNull();
        expect($togglRecord->expires_at)->not->toBeNull();

        // Force driver recreation to clear any internal caches
        Toggl::forgetDriver();

        // When retrieving an expired feature, it should return false (expired means inactive)
        expect(Toggl::for($user)->active('expired-feature'))->toBeFalse();

        // Verify expired record was deleted from Toggl
        expect(DB::table('features')
            ->where('name', 'expired-feature')
            ->exists())->toBeFalse();
    });

    test('getAll retrieves multiple features with correct priority', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 21]);

        // Feature A: only in Pennant (should fall back)
        DB::table('pennant_features')->insert([
            'name' => 'feature-a',
            'scope' => User::class.'|21',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Feature B: in both, Toggl takes priority
        DB::table('pennant_features')->insert([
            'name' => 'feature-b',
            'scope' => User::class.'|21',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Toggl::deactivate('feature-b')->for($user);

        // Feature C: only in Toggl
        Toggl::activate('feature-c')->for($user);

        // Check all features
        expect(Toggl::for($user)->active('feature-a'))->toBeTrue();  // Pennant fallback
        expect(Toggl::for($user)->active('feature-b'))->toBeFalse(); // Toggl priority
        expect(Toggl::for($user)->active('feature-c'))->toBeTrue();  // Toggl only
    });

    test('handles rich values from toggl', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');
        Config::set('toggl.default', 'database');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 22]);

        // Set array value in Toggl using activate with a rich value
        Toggl::for($user)->activate('rich-feature', ['tier' => 'premium', 'limit' => 100]);

        // Should retrieve the rich value
        $value = Toggl::for($user)->value('rich-feature');
        expect($value)->toBe(['tier' => 'premium', 'limit' => 100]);
    });

    test('handles rich values from pennant fallback', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 23]);

        // Insert rich value ONLY in Pennant
        DB::table('pennant_features')->insert([
            'name' => 'pennant-rich-feature',
            'scope' => User::class.'|23',
            'value' => json_encode(['plan' => 'enterprise', 'seats' => 50]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Should retrieve rich value from Pennant fallback
        $value = Toggl::for($user)->value('pennant-rich-feature');
        expect($value)->toBe(['plan' => 'enterprise', 'seats' => 50]);
    });

    test('delete only affects toggl table', function (): void {
        // Enable compatibility mode
        Config::set('toggl.pennant_compatibility.enabled', true);
        Config::set('toggl.pennant_compatibility.table', 'pennant_features');

        // Force driver recreation
        Toggl::forgetDriver();

        // Create a test user
        $user = User::factory()->create(['id' => 24]);

        // Insert in both tables
        DB::table('pennant_features')->insert([
            'name' => 'delete-test-feature',
            'scope' => User::class.'|24',
            'value' => json_encode(true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Toggl::activate('delete-test-feature')->for($user);

        // Verify both exist
        expect(DB::table('pennant_features')->where('name', 'delete-test-feature')->exists())->toBeTrue();
        expect(DB::table('features')->where('name', 'delete-test-feature')->exists())->toBeTrue();

        // Delete via Toggl
        Toggl::for($user)->forget('delete-test-feature');

        // Toggl record should be deleted
        expect(DB::table('features')->where('name', 'delete-test-feature')->exists())->toBeFalse();

        // Pennant record should still exist (untouched)
        expect(DB::table('pennant_features')->where('name', 'delete-test-feature')->exists())->toBeTrue();
    });
});
