<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Fixtures\HierarchicalModel;
use Tests\Fixtures\MorphMapModel;
use Tests\Fixtures\SimpleModel;

describe('HasTogglContext', function (): void {
    describe('Simple Model (no scope)', function (): void {
        test('toTogglContext returns simple context', function (): void {
            $model = new SimpleModel();
            $model->id = 123;
            $model->name = 'Test';

            $context = $model->toTogglContext();

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(123);
            expect($context->type)->toBe(SimpleModel::class);
            expect($context->hasScope())->toBeFalse();
        });

        test('uses model class as type', function (): void {
            $model = new SimpleModel(['id' => 1]);

            $context = $model->toTogglContext();

            expect($context->type)->toBe(SimpleModel::class);
        });
    });

    describe('Hierarchical Model', function (): void {
        test('toTogglContext returns context with scope', function (): void {
            $model = new HierarchicalModel([
                'id' => 456,
                'company_id' => 10,
                'org_id' => 20,
                'team_id' => 30,
            ]);

            $context = $model->toTogglContext();

            expect($context)->toBeInstanceOf(TogglContext::class);
            expect($context->id)->toBe(456);
            expect($context->type)->toBe(HierarchicalModel::class);
            expect($context->hasScope())->toBeTrue();
        });

        test('scope scope includes configured attributes', function (): void {
            $model = new HierarchicalModel([
                'id' => 1,
                'company_id' => 5,
                'org_id' => 10,
                'team_id' => 15,
            ]);

            $context = $model->toTogglContext();
            $scope = $context->scope;

            expect($scope->constraints['company_id'])->toBe(5);
            expect($scope->constraints['org_id'])->toBe(10);
            expect($scope->constraints['team_id'])->toBe(15);
        });

        test('scope scope includes model primary key', function (): void {
            $model = new HierarchicalModel(['id' => 99, 'company_id' => 1]);

            $context = $model->toTogglContext();

            expect($context->scope->constraints['id'])->toBe(99);
        });

        test('uses custom scope kind', function (): void {
            $model = new HierarchicalModel(['id' => 1, 'company_id' => 1]);

            $context = $model->toTogglContext();

            expect($context->scope->kind)->toBe('member');
        });

        test('includes null values in scope scope', function (): void {
            $model = new HierarchicalModel([
                'id' => 1,
                'company_id' => 5,
                'org_id' => null,
                'team_id' => null,
            ]);

            $context = $model->toTogglContext();
            $scope = $context->scope;

            expect($scope->constraints['company_id'])->toBe(5);
            expect($scope->constraints['org_id'])->toBeNull();
            expect($scope->constraints['team_id'])->toBeNull();
        });
    });

    describe('Interface Contract', function (): void {
        test('simple model implements TogglContextable', function (): void {
            $model = new SimpleModel(['id' => 1]);

            expect($model)->toBeInstanceOf(TogglContextable::class);
        });

        test('scoped model implements TogglContextable', function (): void {
            $model = new HierarchicalModel(['id' => 1]);

            expect($model)->toBeInstanceOf(TogglContextable::class);
        });
    });

    describe('getScopeKind() with morphMap', function (): void {
        afterEach(function (): void {
            // Clear morph map after each test
            Relation::morphMap([], merge: false);
        });

        test('uses morphMap alias when configured', function (): void {
            // Arrange
            Relation::enforceMorphMap([
                'custom-morph-alias' => MorphMapModel::class,
            ]);

            $model = new MorphMapModel(['id' => 1, 'company_id' => 5]);

            // Act
            $context = $model->toTogglContext();

            // Assert - scope kind should use morph alias, not class name
            expect($context->scope->kind)->toBe('custom-morph-alias')
                ->and($context->scope->kind)->not->toBe('morphmapmodel')
                ->and($context->scope->kind)->not->toBe(MorphMapModel::class);
        });

        test('uses full class name when no morphMap configured', function (): void {
            // Arrange - explicitly clear morph map
            Relation::morphMap([], merge: false);
            Relation::requireMorphMap(false);

            $modelWithScope = new MorphMapModel(['id' => 1, 'company_id' => 5]);

            // Act
            $contextWithScope = $modelWithScope->toTogglContext();

            // Assert - without morphMap, getMorphClass() returns full class name
            expect($contextWithScope->scope->kind)->toBe(MorphMapModel::class)
                ->and($contextWithScope->scope->kind)->toBe($modelWithScope->getMorphClass());
        });

        test('custom getScopeKind() override takes precedence over morphMap', function (): void {
            // Arrange
            Relation::enforceMorphMap([
                'ignored-alias' => HierarchicalModel::class,
            ]);

            $model = new HierarchicalModel(['id' => 1, 'company_id' => 5]);

            // Act
            $context = $model->toTogglContext();

            // Assert - custom override returns 'member', ignores morphMap
            expect($context->scope->kind)->toBe('member')
                ->and($context->scope->kind)->not->toBe('ignored-alias')
                ->and($context->scope->kind)->not->toBe('hierarchicalmodel');
        });

        test('getMorphClass() result is used for scope kind', function (): void {
            // Arrange
            Relation::enforceMorphMap([
                'product' => MorphMapModel::class,
            ]);

            $model = new MorphMapModel(['id' => 99, 'company_id' => 10]);

            // Act
            $context = $model->toTogglContext();
            $morphClass = $model->getMorphClass();

            // Assert - scope kind should match getMorphClass() result
            expect($context->scope->kind)->toBe($morphClass)
                ->and($morphClass)->toBe('product');
        });

        test('morphMap affects both context type and scope kind', function (): void {
            // Arrange
            Relation::enforceMorphMap([
                'widget' => MorphMapModel::class,
            ]);

            $model = new MorphMapModel(['id' => 42, 'company_id' => 7]);

            // Act
            $context = $model->toTogglContext();

            // Assert - both type and scope kind should use morph alias
            expect($context->type)->toBe('widget')
                ->and($context->scope->kind)->toBe('widget')
                ->and($context->type)->toBe($context->scope->kind);
        });
    });
});
