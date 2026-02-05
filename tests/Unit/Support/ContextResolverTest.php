<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\FeatureScope;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Fixtures\NewStyleModel;
use Tests\Fixtures\PlainModel;
use Tests\Fixtures\ScopeModel;
use Tests\Fixtures\SimpleContextable;
use Tests\Fixtures\User;

describe('ContextResolver', function (): void {
    describe('resolve', function (): void {
        test('resolves TogglContextable using toTogglContext', function (): void {
            $model = new NewStyleModel(['id' => 123, 'company_id' => 5, 'org_id' => 10]);

            $context = ContextResolver::resolve($model);

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(123);
            expect($context->type)->toBe(NewStyleModel::class);
            expect($context->hasScope())->toBeTrue();
            expect($context->scope->constraints['company_id'])->toBe(5);
        });

        test('resolves TogglContextable Model with custom scope', function (): void {
            $model = new ScopeModel(['id' => 456, 'company_id' => 3]);

            $context = ContextResolver::resolve($model);

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(456);
            expect($context->type)->toBe(ScopeModel::class);
            expect($context->hasScope())->toBeTrue();
            expect($context->scope->kind)->toBe('scope');
        });

        test('resolves plain Model without scope', function (): void {
            $model = new PlainModel(['id' => 789, 'name' => 'Test']);

            $context = ContextResolver::resolve($model);

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(789);
            expect($context->type)->toBe(PlainModel::class);
            expect($context->hasScope())->toBeFalse();
        });

        test('resolves TogglContextable without Model', function (): void {
            $contextable = new SimpleContextable(99);

            $context = ContextResolver::resolve($contextable);

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe('simple:99');
            expect($context->type)->toBe(SimpleContextable::class);
            expect($context->hasScope())->toBeFalse();
        });
    });

    describe('hasScope', function (): void {
        test('returns true for TogglContextable with scope', function (): void {
            $model = new NewStyleModel(['id' => 1, 'company_id' => 5]);

            expect(ContextResolver::hasScope($model))->toBeTrue();
        });

        test('returns true for TogglContextable with custom scope', function (): void {
            $model = new ScopeModel(['id' => 1, 'company_id' => 3]);

            expect(ContextResolver::hasScope($model))->toBeTrue();
        });

        test('returns false for plain Model', function (): void {
            $model = new PlainModel(['id' => 1]);

            expect(ContextResolver::hasScope($model))->toBeFalse();
        });

        test('returns false for TogglContextable without scope', function (): void {
            $contextable = new SimpleContextable(1);

            expect(ContextResolver::hasScope($contextable))->toBeFalse();
        });
    });

    describe('extractFeatureScope', function (): void {
        test('extracts from TogglContextable', function (): void {
            $model = new NewStyleModel(['id' => 1, 'company_id' => 5, 'org_id' => 10]);

            $scope = ContextResolver::extractFeatureScope($model);

            expect($scope)->toBeInstanceOf(FeatureScope::class);
            expect($scope->constraints['company_id'])->toBe(5);
        });

        test('extracts from TogglContextable with custom scope', function (): void {
            $model = new ScopeModel(['id' => 1, 'company_id' => 3]);

            $scope = ContextResolver::extractFeatureScope($model);

            expect($scope)->toBeInstanceOf(FeatureScope::class);
            expect($scope->kind)->toBe('scope');
        });

        test('returns null for plain Model', function (): void {
            $model = new PlainModel(['id' => 1]);

            expect(ContextResolver::extractFeatureScope($model))->toBeNull();
        });

        test('returns null for TogglContextable without scope', function (): void {
            $contextable = new SimpleContextable(1);

            expect(ContextResolver::extractFeatureScope($contextable))->toBeNull();
        });
    });

    describe('resolveModel', function (): void {
        afterEach(function (): void {
            Relation::morphMap([], merge: false);
            Relation::requireMorphMap(false);
        });

        test('returns source model when attached', function (): void {
            $user = createUser();
            $context = new TogglContext(
                id: $user->getKey(),
                type: $user->getMorphClass(),
                source: $user,
            );

            $model = ContextResolver::resolveModel($context);

            expect($model)->toBe($user);
        });

        test('resolves model from morph alias and id when source is missing', function (): void {
            Relation::enforceMorphMap([
                'user' => User::class,
            ]);

            $user = createUser();
            $context = new TogglContext(
                id: $user->getKey(),
                type: 'user',
            );

            $model = ContextResolver::resolveModel($context);

            expect($model)->toBeInstanceOf(User::class)
                ->and($model?->getKey())->toBe($user->getKey());
        });

        test('returns null when model cannot be resolved', function (): void {
            $context = new TogglContext(
                id: 999,
                type: 'non-existent',
            );

            expect(ContextResolver::resolveModel($context))->toBeNull();
        });
    });
});
