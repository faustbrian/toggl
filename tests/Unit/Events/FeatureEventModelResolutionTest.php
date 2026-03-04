<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Events\FeatureActivated;
use Cline\Toggl\Events\FeatureDeactivated;
use Cline\Toggl\Events\UnknownFeatureResolved;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Fixtures\User;

describe('Feature Events Model Resolution', function (): void {
    afterEach(function (): void {
        Relation::morphMap([], merge: false);
        Relation::requireMorphMap(false);
    });

    test('FeatureActivated resolves model from context source', function (): void {
        $user = createUser();
        $context = new TogglContext(
            id: $user->getKey(),
            type: $user->getMorphClass(),
            source: $user,
        );

        $event = new FeatureActivated('premium', true, $context);

        expect($event->toModel())->toBe($user);
    });

    test('FeatureDeactivated resolves model from morph alias and id', function (): void {
        Relation::enforceMorphMap([
            'user' => User::class,
        ]);

        $user = createUser();
        $context = new TogglContext(
            id: $user->getKey(),
            type: 'user',
        );

        $event = new FeatureDeactivated('premium', $context);

        expect($event->toModel())->toBeInstanceOf(User::class)
            ->and($event->toModel()?->getKey())->toBe($user->getKey());
    });

    test('UnknownFeatureResolved returns null when model cannot be resolved', function (): void {
        $context = TogglContext::simple('missing', 'unknown');

        $event = new UnknownFeatureResolved('unknown-feature', $context);

        expect($event->toModel())->toBeNull();
    });
});
