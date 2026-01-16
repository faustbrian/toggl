<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl;

use Cline\Toggl\Contracts\Context;
use Cline\Toggl\Drivers\ArrayDriver;
use Cline\Toggl\Drivers\Decorator;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * Feature flag facade for managing feature toggles across your application.
 *
 * This facade provides a clean API for defining, activating, checking, and managing
 * feature flags with support for contextual features (per user, team, etc.), feature groups,
 * variants, and multiple storage drivers.
 *
 * @method static Conductors\ActivationConductor        activate(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static void                                  activateForEveryone(string|\BackedEnum|array<string> $feature, mixed $value = true)
 * @method static void                                  activateGroup(string $name)
 * @method static Conductors\GroupActivationConductor   activateGroupConductor(string $groupName)
 * @method static void                                  activateGroupForEveryone(string $name)
 * @method static bool                                  active(string|\BackedEnum $feature)
 * @method static bool                                  activeInGroup(string $name)
 * @method static bool                                  allAreActive(array<\BackedEnum|string> $features)
 * @method static bool                                  allAreInactive(array<\BackedEnum|string> $features)
 * @method static array<string, array<string>>          allGroups()
 * @method static Conductors\PermissionStyleConductor   allow(mixed $contexts)
 * @method static PendingContextualFeatureInteraction   allowlist(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\ActivationConductor        allowOnly(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\AuditConductor             audit(string|\BackedEnum $feature)
 * @method static Conductors\BatchActivationConductor   batch()
 * @method static Conductors\DeactivationConductor      block(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\BulkValueConductor         bulk(array<string, mixed> $values)
 * @method static string                                calculateVariant(string $feature, mixed $context, array<string, int> $weights)
 * @method static Conductors\CascadeConductor           cascade(string|\BackedEnum $feature)
 * @method static Conductors\CleanupConductor           cleanup()
 * @method static Conductors\ComparisonConductor        compare(mixed $context1, mixed $context2 = null)
 * @method static Context                               context()
 * @method static ArrayDriver                           createArrayDriver()
 * @method static Drivers\CacheDriver                   createCacheDriver(array<string, mixed> $config, string $name)
 * @method static Drivers\DatabaseDriver                createDatabaseDriver(array<string, mixed> $config, string $name)
 * @method static Drivers\GateDriver                    createGateDriver(array<string, mixed> $config, string $name)
 * @method static Conductors\DeactivationConductor      deactivate(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static void                                  deactivateForEveryone(string|\BackedEnum|array<string> $feature)
 * @method static void                                  deactivateGroup(string $name)
 * @method static Conductors\GroupDeactivationConductor deactivateGroupConductor(string $groupName)
 * @method static void                                  deactivateGroupForEveryone(string $name)
 * @method static Conductors\PermissiveConductor        defaultAllow(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static PendingContextualFeatureInteraction   defaultDeny(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static mixed                                 define(string|\BackedEnum $feature, mixed $resolver = null)
 * @method static array<int, string>                    defined()
 * @method static void                                  defineGroup(string $name, array<int, string> $features)
 * @method static void                                  defineVariant(string $name, array<string, int> $weights)
 * @method static void                                  delete(string $feature, mixed $context)
 * @method static Conductors\FluentDefinitionConductor  definition(string|\BackedEnum $feature)
 * @method static Conductors\PermissionStyleConductor   deny(mixed $contexts)
 * @method static Conductors\DeactivationConductor      denyAccess(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\PermissiveConductor        denylist(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\DependencyConductor        dependency(string|\BackedEnum|array<\BackedEnum|string> $prerequisites)
 * @method static bool                                  dependenciesMet(string|\BackedEnum $feature)
 * @method static Conductors\DeactivationConductor      disable(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static void                                  disableGlobally(string|\BackedEnum|array<string> $feature)
 * @method static Decorator                             driver(string|null $name = null)
 * @method static Conductors\ActivationConductor        enable(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static void                                  enableGlobally(string|\BackedEnum|array<string> $feature, mixed $value = true)
 * @method static Support\BatchEvaluationResult         evaluate(array<Support\LazyEvaluation> $evaluations)
 * @method static FeatureManager                        extend(string $driver, \Closure $callback)
 * @method static \Carbon\CarbonInterface|null          expiresAt(string|\BackedEnum $feature)
 * @method static array<string>                         expiringSoon(int $days)
 * @method static void                                  flushCache()
 * @method static PendingContextualFeatureInteraction   for(mixed $context)
 * @method static Conductors\DeactivationConductor      forbid(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static static                                forgetDriver(array<int, string>|string|null $name = null)
 * @method static static                                forgetDrivers()
 * @method static Conductors\CopyConductor              from(mixed $sourceContext)
 * @method static ValueObjects\FeatureValue             get(string|\BackedEnum $feature, mixed $context)
 * @method static array<string, array<int, mixed>>      getAll(array<int|string, mixed>|string $features)
 * @method static array<string, array<int, mixed>>      getAllMissing(array<int|string, mixed>|string $features)
 * @method static string                                getDefaultDriver()
 * @method static array<string>                         getDependencies(string $feature)
 * @method static Contracts\Driver                      getDriver()
 * @method static array<string>                         getGroup(string $name)
 * @method static array<string, int>                    getVariants(string $name)
 * @method static ValueObjects\FeatureValue             getWithScope(string|\BackedEnum $feature, mixed $context, Support\FeatureScope $scope)
 * @method static Conductors\ActivationConductor        grant(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static GroupManager                          groups()
 * @method static bool                                  inactive(string|\BackedEnum $feature)
 * @method static Conductors\InheritConductor           inherit(mixed $childContext)
 * @method static mixed                                 instance(string $name)
 * @method static bool                                  isExpired(string|\BackedEnum $feature)
 * @method static bool                                  isExpiringSoon(string|\BackedEnum $feature, int $days)
 * @method static Conductors\LazyEvaluationConductor    lazy(string|\BackedEnum $feature)
 * @method static void                                  loadGroupsFromConfig()
 * @method static Conductors\MetadataConductor          metadata(string|\BackedEnum $feature)
 * @method static string                                name(string|\BackedEnum $feature)
 * @method static array<string, mixed>                  nameMap()
 * @method static Conductors\ObserveConductor           observe(string|\BackedEnum $feature)
 * @method static PendingContextualFeatureInteraction   optIn(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\ActivationConductor        optInTo(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\PermissiveConductor        optOut(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\DeactivationConductor      optOutFrom(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\PermissiveConductor        permissive(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\PipelineConductor          pipeline()
 * @method static void                                  purge(string|\BackedEnum|array<\BackedEnum|string>|null $features = null)
 * @method static Conductors\DependencyConductor        require(string|\BackedEnum|array<\BackedEnum|string> $prerequisites)
 * @method static void                                  resolveContextUsing(callable $resolver)
 * @method static Conductors\DeactivationConductor      restrict(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static PendingContextualFeatureInteraction   restrictive(string|\BackedEnum|array<\BackedEnum|string> $features)
 * @method static Conductors\RolloutConductor           rollout(string|\BackedEnum $feature)
 * @method static Conductors\ScheduleConductor          schedule(string|\BackedEnum $feature)
 * @method static string                                serializeContext(mixed $context)
 * @method static static                                setContainer(Container $container)
 * @method static static                                setContextManager(Context $context)
 * @method static void                                  setDefaultDriver(string $name)
 * @method static void                                  set(string|\BackedEnum $feature, mixed $context, mixed $value)
 * @method static void                                  setForAllContexts(string $feature, mixed $value)
 * @method static Conductors\SnapshotConductor          snapshot()
 * @method static bool                                  someAreActive(array<\BackedEnum|string> $features)
 * @method static bool                                  someAreInactive(array<\BackedEnum|string> $features)
 * @method static bool                                  someActiveInGroup(string $name)
 * @method static Decorator                             store(string|null $store = null)
 * @method static array<string, mixed>                  stored()
 * @method static Conductors\StrategyConductor          strategy(string|\BackedEnum $feature)
 * @method static Conductors\SyncConductor              sync(mixed $context)
 * @method static Conductors\TestingConductor           testing(string|\BackedEnum|null $feature = null)
 * @method static Conductors\TransactionConductor       transaction()
 * @method static static                                useMorphMap(bool $value = true)
 * @method static mixed                                 value(string|\BackedEnum $feature)
 * @method static Conductors\VariantConductor           variant(string $feature)
 * @method static array<string>                         variantNames(string $name)
 * @method static Conductors\QueryConductor             when(string|\BackedEnum $feature)
 * @method static Conductors\ContextConductor           within(mixed $context)
 *
 * @author Brian Faust <brian@cline.sh>
 * @see FeatureManager
 */
final class Toggl extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The service container binding key
     */
    protected static function getFacadeAccessor(): string
    {
        return FeatureManager::class;
    }
}
