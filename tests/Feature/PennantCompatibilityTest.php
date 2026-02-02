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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    test('reads from Pennant table when compatibility enabled', function (): void {
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

        // Should read from Pennant
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
});
