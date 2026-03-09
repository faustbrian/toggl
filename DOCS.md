## Table of Contents

1. Getting Started (`docs/README.md`)
2. Basic Usage (`docs/basic-usage.md`)
3. Strategies (`docs/strategies.md`)
4. Time Bombs (`docs/time-bombs.md`)
5. Feature Groups (`docs/feature-groups.md`)
6. Dependencies (`docs/dependencies.md`)
7. Variants (`docs/variants.md`)
8. Advanced Usage (`docs/advanced-usage.md`)
9. Middleware (`docs/middleware.md`)
10. Scope Context (`docs/scope-context.md`)
11. Scoped Features (`docs/scoped-features.md`)
12. Snapshot Pruning (`docs/snapshot-pruning.md`)

Welcome to Toggl, a powerful Laravel feature flag package with a conductor-based API for enterprise applications. This guide will help you install, configure, and create your first feature flag.

## Installation

Install Toggl via Composer:

```bash
composer require cline/toggl
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=toggl-config
```

This creates `config/toggl.php` with the following structure:

```php
return [
    'default' => env('FEATURE_FLAGS_STORE', 'database'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION', null),
            'table' => 'features',
        ],
    ],

    'strategies' => [
        'default' => 'boolean',
        'available' => [
            'boolean' => BooleanStrategy::class,
            'time_based' => TimeBasedStrategy::class,
            'percentage' => PercentageStrategy::class,
            'scheduled' => ScheduledStrategy::class,
            'conditional' => ConditionalStrategy::class,
        ],
    ],
];
```

### Driver Selection

**Array Driver** (in-memory)
- Best for: Testing, temporary flags, development
- Data persists only during the current request
- No database required

**Database Driver** (recommended for production)
- Best for: Production environments, persistent flags
- Data stored in database table
- Survives application restarts

To use the database driver, set your `.env`:

```env
FEATURE_FLAGS_STORE=database
```

## Database Setup

If using the database driver, publish and run the migrations:

```bash
php artisan vendor:publish --tag=toggl-migrations
php artisan migrate
```

This creates a `features` table with the following structure:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint/ulid/uuid | Primary key (configurable) |
| `name` | string | Feature flag name |
| `context_type` | string | Polymorphic type (e.g., App\Models\User) |
| `context_id` | bigint/ulid/uuid | Polymorphic ID (type configurable) |
| `value` | text | Feature value (boolean, string, array, etc.) |
| `strategy` | string | Strategy class name (optional) |
| `expires_at` | timestamp | Time bomb expiration (optional) |
| `metadata` | json | Strategy-specific configuration (optional) |

The table uses polymorphic columns (`context_type`, `context_id`) to support different model types and a unique constraint on `(name, context_type, context_id)` to ensure each feature flag can only have one value per context.

## Your First Feature Flag

Let's create a simple feature flag to enable a new dashboard for admin users.

### 1. Define the Feature

In your `AppServiceProvider` or a dedicated `FeatureServiceProvider`:

```php
use Cline\Toggl\Toggl;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Toggl::define('new-dashboard', function ($user) {
            return $user?->isAdmin() ?? false;
        });
    }
}
```

### 2. Check the Feature

In your controller:

```php
use Cline\Toggl\Toggl;

class DashboardController extends Controller
{
    public function index()
    {
        if (Toggl::active('new-dashboard')) {
            return view('dashboard.new');
        }

        return view('dashboard.legacy');
    }
}
```

### 3. Use in Blade Templates

```blade
@feature('new-dashboard')
    <div class="new-dashboard">
        <!-- New dashboard content -->
    </div>
@else
    <div class="legacy-dashboard">
        <!-- Legacy dashboard content -->
    </div>
@endfeature
```

## Understanding Contexts

Feature flags in Toggl are **context-aware**. A context represents the context in which a feature is evaluated - typically a user, team, or organization.

### Default Context

By default, Toggl uses the currently authenticated user as the context:

```php
// These are equivalent when a user is authenticated
Toggl::active('new-dashboard');
Toggl::for(auth()->user())->active('new-dashboard');
```

### Explicit Contexts

You can explicitly specify a context:

```php
// Check for specific user
$user = User::find(123);
if (Toggl::for($user)->active('premium-features')) {
    // ...
}

// Check for team
$team = Team::find(456);
if (Toggl::for($team)->active('team-analytics')) {
    // ...
}

// String context
if (Toggl::for('admin')->active('debug-mode')) {
    // ...
}

// Numeric context
if (Toggl::for(999)->active('special-offer')) {
    // ...
}
```

### Custom Context Resolvers

You can customize how contexts are resolved by defining a context resolver in your service provider:

```php
use Cline\Toggl\Toggl;

Toggl::resolveContextUsing(function ($driver) {
    // Return the current tenant instead of user
    return Tenant::current();
});
```

## Feature Flag Lifecycle

Here's a typical lifecycle for a feature flag:

1. **Define** - Create the feature with a resolver
   ```php
   Toggl::define('new-api', fn($user) => $user->isAdmin());
   ```

2. **Test** - Test with specific users or contexts
   ```php
   Toggl::for($betaTester)->activate('new-api');
   ```

3. **Rollout** - Gradually enable for more users
   ```php
   // Enable for 25% of users
   Toggl::define('new-api', fn($user) => crc32($user->id) % 100 < 25);
   ```

4. **Full Launch** - Enable for everyone
   ```php
   Toggl::activateForEveryone('new-api');
   ```

5. **Cleanup** - Remove flag from code and database
   ```php
   Toggl::purge('new-api');
   ```

## Common Patterns

### Simple Boolean Flag

```php
// Always on
Toggl::define('maintenance-mode', true);

// Always off
Toggl::define('beta-feature', false);
```

### User-Based Flag

```php
Toggl::define('premium-dashboard', function ($user) {
    return $user->subscription?->plan === 'premium';
});
```

### Team-Based Flag

```php
Toggl::define('team-analytics', function ($team) {
    return $team->plan === 'enterprise';
});
```

### Email-Based Flag

```php
Toggl::define('early-access', function ($user) {
    return in_array($user->email, [
        'alice@example.com',
        'bob@example.com',
    ]);
});
```

### Environment-Based Flag

```php
Toggl::define('debug-toolbar', function () {
    return app()->environment('local', 'staging');
});
```

## Best Practices

1. **Naming Conventions**
   - Use lowercase with hyphens: `new-dashboard`, `premium-features`
   - Be descriptive: `ai-chat-assistant` instead of `feature-1`
   - Prefix by area: `api-v2-endpoints`, `ui-dark-mode`

2. **Organization**
   - Group related flags in a dedicated service provider
   - Document flags in comments explaining purpose and rollout plan
   - Remove flags after full rollout

3. **Testing**
   - Always test both active and inactive states
   - Use the array driver for unit tests
   - Test context variations (different users, teams, etc.)

4. **Performance**
   - Feature checks are cached during request lifecycle
   - Database driver is optimized with proper indexing
   - Avoid complex resolvers - keep logic simple

## Next Steps

Now that you have Toggl installed and understand the basics, explore more advanced features:

- **[Basic Usage](basic-usage.md)** - Learn all core operations
- **[Strategies](strategies.md)** - Time-based, percentage, and conditional strategies
- **[Time Bombs](time-bombs.md)** - Auto-expiring features
- **[Feature Groups](feature-groups.md)** - Manage related flags together
- **[Dependencies](dependencies.md)** - Create feature requirements
- **[Variants](variants.md)** - A/B testing with weighted distribution
- **[Advanced Usage](advanced-usage.md)** - Events, custom drivers, and more


This guide covers all the core operations you'll use daily with Toggl feature flags.

## Defining Features

### Simple Boolean Features

The simplest way to define a feature is with a boolean value:

```php
use Cline\Toggl\Toggl;

// Always active
Toggl::define('maintenance-mode', true);

// Always inactive
Toggl::define('upcoming-feature', false);
```

### Using Enums for Type Safety

Define features using BackedEnum for better type safety and IDE autocompletion:

```php
enum FeatureFlag: string
{
    case NewDashboard = 'new-dashboard';
    case BetaApi = 'beta-api';
    case PremiumFeatures = 'premium-features';
}

// Define with enum (no need for ->value)
Toggl::define(FeatureFlag::NewDashboard, true);
Toggl::define(FeatureFlag::BetaApi, fn($user) => $user?->isBetaTester());
```

### Closure-Based Features

For dynamic evaluation, use a closure that receives the current context:

```php
// User-based feature
Toggl::define('premium-dashboard', function ($user) {
    return $user?->subscription?->isPremium() ?? false;
});

// Environment-based feature
Toggl::define('debug-mode', function () {
    return app()->environment('local');
});

// Complex logic
Toggl::define('advanced-search', function ($user) {
    if (!$user) {
        return false;
    }

    return $user->hasRole('admin') ||
           $user->subscription->plan === 'enterprise';
});
```

### Features with Values

Features can store any value, not just booleans:

```php
// String value
Toggl::define('api-version', 'v2');

// Numeric value
Toggl::define('rate-limit', 1000);

// Array value
Toggl::define('ui-config', [
    'theme' => 'dark',
    'sidebar' => 'collapsed',
    'layout' => 'grid',
]);
```

### Fluent Definition API

Define features with a fluent, chainable interface:

```php
// Define with resolver
Toggl::definition('premium')
    ->resolvedBy(fn($user) => $user->subscription === 'premium')
    ->register();

// Define with default value
Toggl::definition('theme')
    ->defaultTo('dark')
    ->register();

// Define with description metadata
Toggl::definition('api-access')
    ->describedAs('API access for integrations')
    ->resolvedBy(fn($user) => $user->hasApiKey())
    ->register();

// Chain description before or after
Toggl::definition('export-limit')
    ->describedAs('Maximum exports per month')
    ->resolvedBy(function ($user) {
        return match ($user->tier) {
            'pro' => 100,
            'enterprise' => 1000,
            default => 10,
        };
    })
    ->register();

// Define role-based feature
Toggl::definition('admin-panel')
    ->describedAs('Administrative panel access')
    ->resolvedBy(fn($user) => $user->role === 'admin')
    ->register();
```

**Use cases:**
- **Readable definitions**: Self-documenting feature definitions
- **Metadata tracking**: Associate descriptions with features
- **Complex resolvers**: Multi-step feature resolution logic
- **Type-safe defaults**: Define default values with type inference

**Behavior:**
- Requires either `resolvedBy()` or `defaultTo()`
- Description is optional metadata (not stored, only on conductor)
- Resolver receives context parameter
- Can redefine features (last registration wins)
- Terminal method: `register()`

## Checking Features

### Active/Inactive Checks

```php
// Check if active
if (Toggl::active('new-dashboard')) {
    // Feature is enabled
}

// Alias: isEnabled (reads more naturally in some contexts)
if (Toggl::isEnabled('new-dashboard')) {
    // Feature is enabled
}

// Check if inactive
if (Toggl::inactive('beta-feature')) {
    // Feature is disabled
}

// Alias: isDisabled
if (Toggl::isDisabled('beta-feature')) {
    // Feature is disabled
}

// With enums (type-safe)
if (Toggl::active(FeatureFlag::NewDashboard)) {
    // Feature is enabled
}
```

### Contextual Checks

Check features for specific users, teams, or any context:

```php
// For specific user
$user = User::find(123);
if (Toggl::for($user)->active('premium-features')) {
    // User has premium features
}

// For team
if (Toggl::for($team)->active('team-analytics')) {
    // Team has analytics
}

// For string context
if (Toggl::for('admin')->active('debug-panel')) {
    // Admin debug panel
}
```

### Multiple Feature Checks

```php
// All features must be active
if (Toggl::allAreActive(['auth', 'api', 'dashboard'])) {
    // All three features are enabled
}

// At least one feature must be active
if (Toggl::someAreActive(['beta-ui', 'new-ui', 'experimental-ui'])) {
    // At least one UI variation is enabled
}

// Alias: anyAreActive (matches Laravel Collection's any() method)
if (Toggl::anyAreActive(['beta-ui', 'new-ui', 'experimental-ui'])) {
    // At least one UI variation is enabled
}

// All features must be inactive
if (Toggl::allAreInactive(['maintenance', 'outage'])) {
    // System is operational
}

// At least one feature is inactive
if (Toggl::someAreInactive(['feature-a', 'feature-b'])) {
    // Not all features are enabled
}

// Alias: anyAreInactive
if (Toggl::anyAreInactive(['feature-a', 'feature-b'])) {
    // Not all features are enabled
}

// Works with enums and mixed arrays
if (Toggl::allAreActive([FeatureFlag::Auth, FeatureFlag::Api, 'legacy-feature'])) {
    // Mix enums and strings
}
```

### Batch Evaluation (Multiple Features × Multiple Contexts)

When you need to check multiple features for multiple contexts simultaneously, use batch evaluation. This is useful for dashboards, admin panels, or any scenario where you need to know the feature state for several users at once.

```php
// Create lazy evaluations
$results = Toggl::evaluate([
    Toggl::lazy('premium')->for($user1),
    Toggl::lazy('premium')->for($user2),
    Toggl::lazy('premium')->for($user3),
    Toggl::lazy('analytics')->for($user1),
    Toggl::lazy('analytics')->for($user2),
]);

// Aggregate checks
$results->all();    // true if ALL evaluations are truthy
$results->any();    // true if ANY evaluation is truthy
$results->none();   // true if ALL evaluations are falsy

// Counting
$results->count();          // Total evaluations (5)
$results->countActive();    // Number of truthy results
$results->countInactive();  // Number of falsy results

// Filter by feature
$premiumResults = $results->forFeature('premium');
$premiumResults->all();     // true if all users have premium
$premiumResults->any();     // true if any user has premium

// Filter by context
$user1Results = $results->forContext($user1);
$user1Results->all();       // true if user1 has all checked features

// Filter by state
$activeOnly = $results->active();     // Only truthy evaluations
$inactiveOnly = $results->inactive(); // Only falsy evaluations

// Chain filters
$results->forFeature('premium')->active()->count(); // Active premium users
```

**Data Access Methods:**

```php
// Simple key-value array
$results->toArray();
// ['premium|App\Models\User|1' => true, 'premium|App\Models\User|2' => false, ...]

// Group by feature
$results->groupByFeature();
// [
//     'premium' => ['App\Models\User|1' => true, 'App\Models\User|2' => false],
//     'analytics' => ['App\Models\User|1' => true, ...]
// ]

// Group by context
$results->groupByContext();
// [
//     'App\Models\User|1' => ['premium' => true, 'analytics' => true],
//     'App\Models\User|2' => ['premium' => false, 'analytics' => false],
// ]

// Get unique feature names
$results->features(); // ['premium', 'analytics']

// Get raw entries for custom processing
$results->entries();  // EvaluationEntry[]

// Laravel Collection for advanced operations
$results->collect()->filter(fn($e) => $e->isActive())->map(...);

// Empty checks
$results->isEmpty();
$results->isNotEmpty();
```

**Works with BackedEnum:**

```php
enum Feature: string
{
    case Premium = 'premium';
    case Analytics = 'analytics';
    case Reporting = 'reporting';
}

$results = Toggl::evaluate([
    Toggl::lazy(Feature::Premium)->for($user1),
    Toggl::lazy(Feature::Analytics)->for($user1),
    Toggl::lazy(Feature::Reporting)->for($user2),
]);
```

**Real-World Example - Admin Dashboard:**

```php
// Check feature access for all team members
$team = Team::with('users')->find($teamId);
$features = ['premium', 'analytics', 'api-access', 'export'];

$evaluations = [];
foreach ($team->users as $user) {
    foreach ($features as $feature) {
        $evaluations[] = Toggl::lazy($feature)->for($user);
    }
}

$results = Toggl::evaluate($evaluations);

// Build dashboard data
$dashboard = [
    'total_checks' => $results->count(),
    'active_count' => $results->countActive(),
    'by_feature' => [],
    'by_user' => [],
];

foreach ($features as $feature) {
    $featureResults = $results->forFeature($feature);
    $dashboard['by_feature'][$feature] = [
        'active' => $featureResults->countActive(),
        'total' => $featureResults->count(),
    ];
}

foreach ($team->users as $user) {
    $userResults = $results->forContext($user);
    $dashboard['by_user'][$user->id] = [
        'active_features' => $userResults->countActive(),
        'all_active' => $userResults->all(),
    ];
}
```

**When to use batch evaluation:**
- Checking features for multiple users (admin dashboards, reports)
- Bulk permission checks before operations
- Feature matrix displays
- Analytics and reporting on feature adoption
- Any scenario with multiple features × multiple contexts

**Difference from `batch()`:**
- `batch()` is for **activation/deactivation** (write operations)
- `evaluate()` is for **checking status** (read operations)

## Retrieving Values

### Single Value

```php
// Get feature value (returns mixed)
$apiVersion = Toggl::value('api-version'); // 'v2'
$rateLimit = Toggl::value('rate-limit');   // 1000
$config = Toggl::value('ui-config');       // ['theme' => 'dark', ...]

// With context
$userTheme = Toggl::for($user)->value('theme-preference');
```

### Multiple Values

```php
// Get multiple values at once
$values = Toggl::values(['api-version', 'rate-limit', 'ui-config']);
// [
//     'api-version' => 'v2',
//     'rate-limit' => 1000,
//     'ui-config' => [...]
// ]
```

### Value with Check

Check if feature matches a specific value:

```php
// In Blade
@feature('api-version', 'v2')
    <!-- Using API v2 -->
@endfeature

// In PHP
if (Toggl::value('api-version') === 'v2') {
    // Use v2 endpoints
}
```

## Activating and Deactivating Features

### Global Activation/Deactivation

```php
// Activate (sets to true)
Toggl::activate('new-feature');

// Activate with custom value
Toggl::activate('api-version', 'v3');

// Deactivate (sets to false)
Toggl::deactivate('old-feature');

// Activate multiple features
Toggl::activate(['feature-a', 'feature-b', 'feature-c']);

// Deactivate multiple features
Toggl::deactivate(['old-ui', 'deprecated-api']);
```

### Contextual Activation/Deactivation

```php
// Traditional context-first pattern
Toggl::for($user)->activate('beta-access');

// Activate with value
Toggl::for($user)->activate('theme', 'dark');

// Deactivate for specific user
Toggl::for($user)->deactivate('beta-access');

// Activate for team
Toggl::for($team)->activate('team-dashboard');
```

### Conductor Pattern (Feature-First)

For a more fluent API, use the conductor pattern where you specify the feature first:

```php
// Activate feature for single context
Toggl::activate('premium')->for($user);

// Activate for multiple contexts
Toggl::activate('premium')->for([$user1, $user2, $user3]);

// Activate with custom value - use withValue() chain
Toggl::activate('theme')->withValue('dark')->for($user);

// Activate with array/object values
Toggl::activate('settings')->withValue([
    'notifications' => true,
    'theme' => 'dark',
    'language' => 'en',
])->for($user);

// Activate multiple features for context
Toggl::activate(['feat-1', 'feat-2', 'feat-3'])->for($user);

// Deactivate feature for context(s)
Toggl::deactivate('beta')->for($user);
Toggl::deactivate('beta')->for([$user1, $user2]);
```

**Why use the conductor pattern?**
- More natural when activating the same feature(s) for multiple contexts
- Mirrors common patterns from packages like Warden
- Reads naturally: "activate premium for these users"
- The `withValue()` chain makes value assignment explicit and discoverable
- Both patterns work identically - use whichever reads better for your use case

### Context Grouping (Within)

When performing multiple operations on the same context, use `within()` to avoid repetition:

```php
// Traditional approach (repetitive)
Toggl::for($team)->activate('dashboard');
Toggl::for($team)->activate('analytics');
Toggl::for($team)->activate('reporting');
Toggl::for($team)->deactivate('legacy-ui');

// Context grouping approach (cleaner)
Toggl::within($team)
    ->activate('dashboard')
    ->activate('analytics')
    ->activate('reporting')
    ->deactivate('legacy-ui');

// Mix with values
Toggl::within($user)
    ->activate('premium')
    ->activateWithValue('theme', 'dark')
    ->activateWithValue('language', 'es')
    ->deactivate('beta-features');

// Works with groups too
Toggl::within($team)
    ->activateGroup('premium-features')
    ->deactivate('old-feature')
    ->activate('new-feature');
```

### Bulk Values

Set multiple feature/value pairs at once for one or more contexts:

```php
// Set multiple user preferences
Toggl::bulk([
    'theme' => 'dark',
    'language' => 'es',
    'timezone' => 'America/New_York',
])->for($user);

// Set configuration for multiple contexts
Toggl::bulk([
    'plan' => 'enterprise',
    'max-members' => 100,
    'features-enabled' => ['analytics', 'reporting', 'api-access'],
])->for([$team1, $team2, $team3]);

// Complex values supported
Toggl::bulk([
    'string-val' => 'text',
    'int-val' => 42,
    'bool-val' => true,
    'array-val' => ['a', 'b', 'c'],
    'null-val' => null,
])->for($user);

// Common scenarios
// New user onboarding
Toggl::bulk([
    'theme' => 'light',
    'language' => 'en',
    'timezone' => 'UTC',
    'email-verified' => false,
    'onboarding-completed' => false,
])->for($newUser);

// API configuration
Toggl::bulk([
    'api-enabled' => true,
    'api-version' => 'v2',
    'rate-limit' => 1000,
    'allowed-endpoints' => ['users', 'posts', 'comments'],
])->for($user);
```

**Difference from batch():**
- `bulk()` sets multiple feature/value pairs → single context(s)
- `batch()` does Cartesian product: features × contexts

### Batch Operations (Cartesian Product)

Activate/deactivate multiple features for multiple contexts efficiently:

```php
// Single feature for multiple users
Toggl::batch()
    ->activate('premium')
    ->for([$user1, $user2, $user3]);

// Multiple features for single user
Toggl::batch()
    ->activate(['premium', 'analytics', 'api-access'])
    ->for($user);

// Cartesian product: all features × all contexts (9 operations)
Toggl::batch()
    ->activate(['premium', 'analytics', 'api-access'])
    ->for([$user1, $user2, $user3]);

// With custom values
Toggl::batch()
    ->activate('theme', 'dark')
    ->for([$user1, $user2]);

// Deactivation
Toggl::batch()
    ->deactivate(['trial-1', 'trial-2'])
    ->for($expiredUsers);

// Mass activation for new cohort
Toggl::batch()
    ->activate(['premium-ui', 'advanced-search', 'export', 'analytics'])
    ->for($newPremiumUsers);
```

**How it works:**
- Executes all features × all contexts (Cartesian product)
- Single batch operation for efficiency
- Useful for mass rollouts, cohort management, trial expirations

### Permission-Style API (Warden-inspired)

Grant or revoke feature access with permission-style syntax:

```php
// Allow single feature for single user
Toggl::allow($user)->to('premium-dashboard');

// Allow multiple features for single user
Toggl::allow($user)->to(['premium', 'analytics', 'api-access']);

// Allow single feature for multiple users
Toggl::allow([$user1, $user2, $user3])->to('beta-access');

// Allow multiple features for multiple users (Cartesian product)
Toggl::allow([$user1, $user2])->to(['feature-1', 'feature-2', 'feature-3']);

// Deny (revoke) access
Toggl::deny($user)->to('beta-features');
Toggl::deny([$user1, $user2])->to(['trial-1', 'trial-2']);

// Group operations
Toggl::allow($user)->toGroup('premium');
Toggl::deny($user)->toGroup('experimental');

// Grant premium to new subscribers
Toggl::allow($newSubscribers)->to([
    'premium-ui',
    'advanced-search',
    'export',
    'analytics',
    'priority-support',
]);

// Revoke trial access on expiration
Toggl::deny($expiredUsers)->to(['trial-1', 'trial-2', 'trial-3']);
```

**When to use:**
- Onboarding flows: "allow user to features"
- Access revocation: "deny user from features"
- Clear permission semantics
- Bulk user management operations

**How it works:**
- `allow()` activates features (same as `activate()`)
- `deny()` deactivates features (same as `deactivate()`)
- Supports Cartesian product for bulk operations
- More intuitive for permission-based scenarios

### Strategy-Based Activation

Apply intelligent activation strategies for gradual rollouts and experiments:

```php
// Percentage-based rollout (0-100%)
Toggl::strategy('new-dashboard')
    ->percentage(25)
    ->for($user);

// Time-based activation (scheduled features)
Toggl::strategy('holiday-sale')
    ->from('2025-12-01')
    ->until('2025-12-31')
    ->for($user);

// Global time-based activation (no context)
Toggl::strategy('scheduled-maintenance')
    ->from('2025-06-01')
    ->until('2025-06-02')
    ->activate();

// Only start date (runs forever after start)
Toggl::strategy('permanent-feature')
    ->from('2025-01-01')
    ->for($user);

// Only end date (active until expiration)
Toggl::strategy('temporary-promo')
    ->until('2025-03-31')
    ->for($user);

// Variant distribution for A/B/n testing
Toggl::strategy('ui-experiment')
    ->variants(['control' => 50, 'variant-a' => 30, 'variant-b' => 20])
    ->for($user);
```

**Use cases:**
- **Percentage rollout**: Gradual feature deployment (start 10%, increase to 100%)
- **Time-based**: Seasonal features, promotions, scheduled releases
- **Variants**: A/B testing, multivariate experiments

**How it works:**
- Percentage uses CRC32 hashing for consistent user assignment
- Time-based checks current date against `from`/`until` range
- Variant strategy integrates with variant conductor
- All assignments are deterministic per user

### Feature Dependencies

Enforce prerequisite features before activating dependent features:

```php
// Basic dependency pattern
Toggl::require('basic-analytics')
    ->before('advanced-analytics')
    ->for($user);

// Multiple prerequisites (all must be active)
Toggl::require(['auth', 'payment', 'subscription'])
    ->before('premium-suite')
    ->for($user);

// Alternative pattern: activate with requirements
Toggl::activate('checkout')
    ->requires(['auth', 'payment'])
    ->for($user);

// Single prerequisite
Toggl::activate('analytics')
    ->requires('basic-plan')
    ->for($user);
```

**Use cases:**
- **Tiered access**: Require lower tiers before granting higher tiers
- **Workflow progression**: Ensure onboarding steps completed
- **Feature gates**: Prevent advanced features without prerequisites

**Behavior:**
- Throws `RuntimeException` if any prerequisite is missing
- Error message lists all missing prerequisites
- Only activates dependent feature if all requirements met
- Works with both single and multiple prerequisites

**Example errors:**
```php
// Missing one prerequisite
"Cannot activate 'premium-suite': missing prerequisites [subscription]"

// Missing multiple prerequisites
"Cannot activate 'premium-suite': missing prerequisites [payment, subscription]"
```

### Copy Features Between Contexts

Copy features from one context to another efficiently:

```php
// Copy all features from source to target
Toggl::from($adminTemplate)->copyTo($newAdmin);

// Selective copy with only() - whitelist approach
Toggl::from($prodUser)
    ->only(['premium-ui', 'advanced-search', 'export'])
    ->copyTo($testUser);

// Filtered copy with except() - blacklist approach
Toggl::from($oldAccount)
    ->except(['trial-banner', 'onboarding-wizard'])
    ->copyTo($newAccount);
```

**Use cases:**
- **Template users**: Copy features from admin template to new admins
- **Account migration**: Transfer features excluding temporary ones
- **Testing**: Copy production-safe features to test accounts

**Behavior:**
- Copies all active features with their values
- `only()` creates whitelist (only specified features copied)
- `except()` creates blacklist (all except specified copied)
- Overwrites existing features on target
- Does nothing if source has no features

### Cascade

Activate or deactivate a primary feature along with all dependent features:

```php
// Activate premium and all dependent features
Toggl::cascade('premium')
    ->activating(['analytics', 'export', 'api-access'])
    ->for($user);

// Deactivate premium and cascade to dependents
Toggl::cascade('premium')
    ->deactivating(['analytics', 'export', 'api-access'])
    ->for($user);

// Subscription upgrade with all features
Toggl::cascade('enterprise-plan')
    ->activating([
        'advanced-analytics',
        'priority-support',
        'custom-integrations',
        'api-access',
        'white-label',
    ])
    ->for($organization);

// Module activation with sub-features
Toggl::cascade('crm-module')
    ->activating(['contacts', 'deals', 'tasks', 'calendar'])
    ->for($user);
```

**Use cases:**
- **Subscription changes**: Upgrade/downgrade with all tier features
- **Module management**: Enable/disable feature bundles
- **Beta programs**: Activate experimental features together
- **Dependency cleanup**: Remove features and their dependents

### Testing / Fakes

Create test doubles for features during testing:

```php
// Fake single feature as enabled
Toggl::testing('premium')
    ->fake(true)
    ->for($user);

// Fake feature with specific value
Toggl::testing('api-limit')
    ->fake(100)
    ->for($user);

// Fake multiple features at once
Toggl::testing()
    ->fakeMany([
        'premium' => true,
        'analytics' => true,
        'api-limit' => 100,
        'theme' => 'dark',
    ])
    ->for($user);

// Fake globally for all contexts
Toggl::testing('debug-mode')
    ->fake(true)
    ->globally();

// Test premium features
Toggl::testing()
    ->fakeMany([
        'premium' => true,
        'export-limit' => 1000,
        'priority-support' => true,
    ])
    ->for($testUser);
```

**Use cases:**
- **Unit testing**: Test premium-only code paths
- **Integration testing**: Set up complete feature environments
- **Edge case testing**: Test specific limits and values
- **Fallback testing**: Disable features to test fallback behavior

### Pipeline

Chain multiple feature operations in a single fluent pipeline:

```php
// Subscription upgrade - remove old, add new
Toggl::pipeline()
    ->deactivate(['basic-dashboard', 'basic-support'])
    ->activate(['premium-dashboard', 'analytics', 'export', 'priority-support'])
    ->for($user);

// Feature migration with logging
Toggl::pipeline()
    ->tap(fn() => logger('Starting migration'))
    ->deactivate('old-api')
    ->tap(fn() => logger('Deactivated old API'))
    ->activate('new-api')
    ->tap(fn() => logger('Activated new API'))
    ->for($user);

// Staged rollout
Toggl::pipeline()
    ->activate(['new-ui-phase-1', 'new-ui-phase-2', 'new-ui-phase-3'])
    ->deactivate('old-ui')
    ->for($user);

// Beta enrollment with cleanup
Toggl::pipeline()
    ->deactivate('old-beta')
    ->activate(['new-beta-ui', 'new-beta-api', 'debug-mode'])
    ->tap(fn($context) => event(new BetaEnrolled($context)))
    ->for($user);
```

**Use cases:**
- **Subscription changes**: Atomic upgrade/downgrade operations
- **Feature migrations**: Replace old features with new ones
- **Staged rollouts**: Activate multiple phases in order
- **Complex workflows**: Chain activations, deactivations, and callbacks

**Behavior:**
- Operations execute in order (activate → deactivate → tap)
- Cascade: Primary feature activated first, then dependents
- Cascade: Dependents deactivated first, then primary
- Idempotent operations (safe to cascade already-active features)
- Empty dependent array only affects primary feature
- Tap callbacks receive context parameter

### Tap (Side Effects)

Execute callbacks without breaking the fluent chain:

```php
// Log during activation
Toggl::activate('premium')
    ->tap(fn($conductor) => Log::info("Activating: {$conductor->features()}"))
    ->for($user);

// Multiple taps in chain
Toggl::activate('premium')
    ->tap(fn() => Cache::forget('user-features'))
    ->tap(fn() => event(new PremiumActivated($user)))
    ->tap(fn() => Log::info('Premium activated'))
    ->for($user);

// Access conductor data in tap
Toggl::activate('theme')
    ->withValue('dark')
    ->tap(function($conductor) {
        Log::info('Setting value', [
            'feature' => $conductor->features(),
            'value' => $conductor->value(),
        ]);
    })
    ->for($user);

// Audit trail
Toggl::activate('premium')
    ->tap(function($conductor) use ($user) {
        AuditLog::create([
            'action' => 'feature_activation',
            'feature' => $conductor->features(),
            'user_id' => $user->id,
        ]);
    })
    ->for($user);

// Cache invalidation
Toggl::activate('settings')
    ->withValue(['theme' => 'dark', 'lang' => 'es'])
    ->tap(fn() => Cache::tags(['user-settings'])->flush())
    ->for($user);
```

## Transaction Conductor

Execute atomic feature operations with automatic rollback on failure:

```php
// Basic transaction
Toggl::transaction()
    ->activate('premium')
    ->activate('analytics')
    ->commit($user);

// Subscription upgrade
Toggl::transaction()
    ->deactivate(['basic-plan', 'basic-support'])
    ->activate(['premium-plan', 'priority-support', 'analytics', 'export'])
    ->commit($user);

// Manual rollback capability
$transaction = Toggl::transaction()
    ->deactivate('v1-api')
    ->activate('v2-api');

$transaction = $transaction->commit($user);

// If something goes wrong, rollback
if ($migrationFailed) {
    $transaction->rollback($user);
}

// Failure handling with callback
Toggl::transaction()
    ->activate('premium')
    ->activate('analytics')
    ->onFailure(function($exception, $context) {
        Log::error('Transaction failed', [
            'error' => $exception->getMessage(),
            'user' => $context->id,
        ]);
    })
    ->commit($user);
```

**Key Features:**
- Atomic operations: All succeed or all rollback
- Initial state captured on commit
- `rollback()` restores original state
- `onFailure()` callback for error handling
- Returns new instance with captured state

## Metadata Conductor

Manage feature metadata with fluent API for set, merge, forget, and clear operations:

```php
// Set metadata (replaces existing)
Toggl::metadata('premium')
    ->with([
        'plan' => 'monthly',
        'price' => 9.99,
        'trial_ends' => now()->addDays(14),
    ])
    ->for($user);

// Merge with existing metadata
Toggl::metadata('premium')
    ->merge([
        'upgraded_at' => now(),
        'previous_plan' => 'basic',
    ])
    ->for($user);

// Forget specific keys
Toggl::metadata('premium')
    ->forget(['trial_ends', 'previous_plan'])
    ->for($user);

// Clear all metadata
Toggl::metadata('premium')
    ->clear()
    ->for($user);

// Subscription lifecycle example
Toggl::metadata('premium')
    ->with(['plan' => 'monthly', 'price' => 9.99])
    ->for($user);

// Upgrade
Toggl::metadata('premium')
    ->merge(['plan' => 'yearly', 'price' => 99.99, 'upgraded_at' => now()])
    ->for($user);

// Cleanup
Toggl::metadata('premium')
    ->forget(['upgraded_at'])
    ->for($user);
```

**Key Features:**
- `with()` replaces all metadata
- `merge()` merges with existing
- `forget()` removes specific keys
- `clear()` removes all metadata
- Works with nested arrays

**Note:** `tap()` executes before the terminal `for()` method, making it perfect for logging, event dispatching, cache invalidation, and other side effects.

## Audit Conductor

Track feature state changes with audit history logging for compliance and debugging:

```php
// Log activation with reason
Toggl::audit('premium')
    ->activate()
    ->withReason('Subscription upgraded')
    ->for($user);

// Log deactivation with actor
Toggl::audit('trial')
    ->deactivate()
    ->withReason('Trial period ended')
    ->withActor($admin)
    ->for($user);

// Retrieve audit history
$history = Toggl::audit('premium')->history($user);
// Returns: [
//   [
//     'action' => 'activate',
//     'reason' => 'Subscription upgraded',
//     'timestamp' => '2024-01-15T10:30:00Z'
//   ],
//   ...
// ]

// Clear audit history
Toggl::audit('premium')->clearHistory($user);

// Compliance tracking
Toggl::audit('data-export')
    ->deactivate()
    ->withReason('GDPR compliance - data retention policy')
    ->withActor($admin)
    ->for($user);

// Beta enrollment audit trail
Toggl::audit('beta-ui')
    ->activate()
    ->withReason('User opted into beta program')
    ->for($user);
```

**Key Features:**
- `activate()` / `deactivate()` - Set the action to log
- `withReason()` - Add context for the change
- `withActor()` - Track who made the change
- `history()` - Retrieve chronological audit trail
- `clearHistory()` - Remove audit logs
- Automatic timestamp on all entries
- Each feature has separate history

**Use Cases:**
- Compliance and regulatory requirements
- Debugging feature state changes
- User behavior tracking
- Admin action logging
- Subscription lifecycle tracking

## Snapshot Conductor

Capture and restore complete feature states for backups, rollbacks, and testing with comprehensive audit trails:

```php
// Capture current state
$snapshotId = Toggl::snapshot()->capture($user);

// Restore from snapshot
Toggl::snapshot()->restore($snapshotId, $user);

// Named snapshots with metadata
$backupId = Toggl::snapshot()
    ->withLabel('pre-migration')
    ->withMetadata(['reason' => 'v2 API migration', 'team' => 'backend'])
    ->capture($user, createdBy: $admin);

// Restore specific features only (granular restore)
Toggl::snapshot()->restorePartial($snapshotId, $user, [
    'api-version',
    'feature-flags',
], restoredBy: $admin);

// List all snapshots
$snapshots = Toggl::snapshot()->list($user);
// Returns: [
//   [
//     'id' => 'snapshot_...',
//     'label' => 'pre-migration',
//     'timestamp' => '2024-01-15T10:30:00Z',
//     'features' => [...],
//     'metadata' => [...],
//     'created_by' => ['type' => 'App\Models\User', 'id' => 1],
//     'restored_at' => '2024-01-15T11:00:00Z',
//     'restored_by' => ['type' => 'App\Models\User', 'id' => 1]
//   ],
//   ...
// ]

// Get specific snapshot
$snapshot = Toggl::snapshot()->get($snapshotId, $user);

// Get event history (audit trail)
$events = Toggl::snapshot()->getEventHistory($snapshotId);
// Returns: [
//   [
//     'id' => 'event_...',
//     'type' => 'created',
//     'performed_by' => ['type' => 'App\Models\User', 'id' => 1],
//     'metadata' => ['feature_count' => 5],
//     'created_at' => '2024-01-15T10:30:00Z'
//   ],
//   [
//     'id' => 'event_...',
//     'type' => 'restored',
//     'performed_by' => ['type' => 'App\Models\User', 'id' => 2],
//     'metadata' => ['features_restored' => ['api-version', 'auth']],
//     'created_at' => '2024-01-15T11:00:00Z'
//   ],
//   ...
// ]

// Delete snapshot with audit
Toggl::snapshot()->delete($snapshotId, $user, deletedBy: $admin);

// Clear all snapshots with audit
Toggl::snapshot()->clearAll($user, deletedBy: $admin);

// Migration backup example with full audit trail
$backup = Toggl::snapshot()
    ->withLabel('pre-v2-migration')
    ->withMetadata([
        'migration_id' => 'v2-2024-01',
        'rollback_plan' => 'Restore API v1 endpoints',
    ])
    ->capture($user, createdBy: $admin);

// Perform migration
Toggl::for($user)->deactivate(['v1-api', 'v1-ui']);
Toggl::for($user)->activate(['v2-api', 'v2-ui']);

// Rollback if needed
if ($migrationFailed) {
    Toggl::snapshot()->restore($backup, $user, restoredBy: $admin);

    // Review what was restored
    $events = Toggl::snapshot()->getEventHistory($backup);
    Log::info('Migration rolled back', $events);
}
```

**Key Features:**
- `capture($context, $createdBy)` - Save current feature state with audit
- `restore($id, $context, $restoredBy)` - Revert to saved state with audit
- `restorePartial($id, $context, $features, $restoredBy)` - Restore specific features only
- `withLabel($label)` - Add descriptive label
- `withMetadata($metadata)` - Attach custom metadata
- `getEventHistory($id)` - Retrieve complete audit trail
- `list($context)` - Get all snapshots
- `get($id, $context)` - Retrieve specific snapshot
- `delete($id, $context, $deletedBy)` - Remove snapshot with audit
- `clearAll($context, $deletedBy)` - Delete all snapshots with audit
- Captures both feature activation and values
- Unique IDs for each snapshot

**Storage Configuration:**
Configure snapshot functionality with dedicated storage driver for optimized performance and complete historical tracking:

```php
// In config/toggl.php
use Cline\Toggl\Enums\SnapshotDriver;

'snapshots' => [
    // Enable or disable snapshot functionality
    'enabled' => env('TOGGL_SNAPSHOTS_ENABLED', true),

    // Use database for complete historical tracking (recommended)
    'driver' => SnapshotDriver::Database,

    // Or use array for in-memory snapshots (testing)
    'driver' => SnapshotDriver::Array,

    // Or use cache for temporary snapshots (TTL-based)
    'driver' => SnapshotDriver::Cache,

    // null = use same driver as main feature store
    'driver' => null,
],
```

**Driver Capabilities:**

| Driver   | Historical Tracking | Event Audit | Granular Restore | Persistence |
|----------|-------------------|-------------|-----------------|-------------|
| Database | Full              | Complete    | Yes             | Permanent   |
| Array    | Session           | In-memory   | Yes             | Request     |
| Cache    | TTL-based         | In-memory   | Yes             | TTL         |

**Database Driver** (Recommended for Production):
- Dedicated tables: `feature_snapshots`, `feature_snapshot_entries`, `feature_snapshot_events`
- Complete audit trail with who/when/why
- Granular restore of individual features
- Event history: created, restored, deleted, partial_restore
- Permanent storage for compliance and debugging

**Array/Cache Drivers** (Development/Testing):
- Stores snapshots in `__snapshots__` key
- Basic event tracking in memory
- Suitable for temporary states
- Faster for ephemeral use cases

**Use Cases:**
- Migration backup and rollback (database driver)
- A/B test variant switching (any driver)
- Testing environment setup (array driver)
- State recovery after errors (database driver)
- Feature configuration versioning (database driver)
- Compliance audit trails (database driver)
- Debugging feature state changes (database driver)
- Temporary state captures (cache/array driver)

## Cleanup Conductor

Remove stale data and old records based on retention policies:

```php
// Clean old snapshots (older than 30 days)
$removed = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->for($user);

// Keep only latest 5 snapshots
$removed = Toggl::cleanup()
    ->snapshots()
    ->keepLatest(5)
    ->for($user);

// Clean old audit history
$removed = Toggl::cleanup()
    ->auditHistory()
    ->olderThan(90)
    ->for($user);


// Combine filters (remove old + keep latest)
$removed = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->keepLatest(10)
    ->for($user);

// Scheduled maintenance example
// Run daily: clean snapshots older than 30 days, keep latest 5
$snapshotsRemoved = Toggl::cleanup()
    ->snapshots()
    ->olderThan(30)
    ->keepLatest(5)
    ->for($user);

// Compliance retention: keep audit logs for 7 years
$auditRemoved = Toggl::cleanup()
    ->auditHistory()
    ->olderThan(2555) // ~7 years
    ->for($user);
```

**Key Features:**
- `snapshots()` - Clean old snapshots
- `auditHistory()` - Clean old audit logs
- `olderThan($days)` - Keep only items newer than N days
- `keepLatest($count)` - Keep only N most recent items
- Filters can be combined
- Returns count of items removed

**Use Cases:**
- Scheduled maintenance cleanup
- Compliance retention policies
- Migration cleanup after rollback
- Database size management
- Removing stale test data

## Schedule Conductor

Schedule time-based feature activation and deactivation:

```php
// Activate at specific time
Toggl::schedule('promotion')
    ->activateAt(new DateTime('2024-12-25 00:00:00'))
    ->for($user);

// Deactivate at specific time
Toggl::schedule('trial')
    ->deactivateAt(new DateTime('+30 days'))
    ->for($user);

// Active only within time window
$isActive = Toggl::schedule('flash-sale')
    ->between(
        new DateTime('2024-12-25 00:00:00'),
        new DateTime('2024-12-25 23:59:59')
    )
    ->for($user);

// Use string dates (supports strtotime)
Toggl::schedule('beta-enrollment')
    ->between('-3 days', '+4 days')
    ->for($user);

// Activate with custom value
Toggl::schedule('premium')
    ->activateAt('-1 hour')
    ->withValue(['tier' => 'gold', 'credits' => 100])
    ->for($user);

// Save schedule for later evaluation
$scheduleId = Toggl::schedule('maintenance-mode')
    ->between('+8 hours', '+10 hours')
    ->withValue(['message' => 'System maintenance in progress'])
    ->save($user);

// List all saved schedules
$schedules = Toggl::schedule('any')->listSchedules($user);

// Delete saved schedule
Toggl::schedule('maintenance-mode')->deleteSchedule($scheduleId, $user);

// Apply all saved schedules (returns count of state changes)
$updated = Toggl::schedule('any')->applyAll($user);

// Flash sale example
$isActive = Toggl::schedule('christmas-sale')
    ->between('2024-12-25 00:00:00', '2024-12-25 23:59:59')
    ->withValue(['discount' => 50])
    ->for($user);

if ($isActive) {
    $discount = Toggl::for($user)->value('christmas-sale')['discount'];
}

// Trial expiration
Toggl::schedule('trial')
    ->deactivateAt((new DateTime())->modify('+30 days'))
    ->for($user);

// Scheduled maintenance window
$scheduleId = Toggl::schedule('maintenance-mode')
    ->between('2024-12-31 22:00:00', '2024-12-31 23:59:59')
    ->withValue(['message' => 'Scheduled maintenance'])
    ->save($user);
```

**Key Features:**
- `activateAt($time)` - Activate at specific time
- `deactivateAt($time)` - Deactivate at specific time
- `between($start, $end)` - Active only within time window
- `withValue($value)` - Set custom value when activated
- `save($context)` - Save schedule for later evaluation
- `listSchedules($context)` - List all saved schedules
- `deleteSchedule($id, $context)` - Delete saved schedule
- `applyAll($context)` - Apply all saved schedules
- Accepts DateTime objects or strings
- Returns boolean (is feature active)

**Use Cases:**
- Flash sales and limited-time promotions
- Trial period expirations
- Scheduled maintenance windows
- Beta enrollment periods
- Time-limited feature access
- Holiday-specific features
- External scheduler integration via `save()` and `applyAll()`

## Rollout Conductor

Gradual feature rollouts with percentage-based activation:

```php
// Roll out to 25% of users
Toggl::rollout('new-dashboard')
    ->toPercentage(25)
    ->for($user);

// Gradually increase rollout
Toggl::rollout('new-dashboard')
    ->toPercentage(50)
    ->for($user);

// Full rollout
Toggl::rollout('new-dashboard')
    ->toPercentage(100)
    ->for($user);

// Emergency rollback
Toggl::rollout('problematic-feature')
    ->toPercentage(0)
    ->for($user);

// Sticky rollouts (consistent user assignment)
$isActive = Toggl::rollout('beta-features')
    ->toPercentage(10)
    ->withStickiness(true)
    ->for($user);

// Custom seed for deterministic rollouts
$isActive = Toggl::rollout('experiment')
    ->toPercentage(50)
    ->withStickiness(true)
    ->withSeed('experiment-1')
    ->for($user);

// Non-sticky rollout (random each time)
$isActive = Toggl::rollout('temporary-banner')
    ->toPercentage(30)
    ->withStickiness(false)
    ->for($user);

// Canary deployment (1% of users)
Toggl::rollout('api-v2')
    ->toPercentage(1)
    ->withStickiness(true)
    ->withSeed('api-migration')
    ->for($user);

// A/B test with 50/50 split
$inVariantA = Toggl::rollout('experiment-variant-a')
    ->toPercentage(50)
    ->withStickiness(true)
    ->withSeed('ab-test-1')
    ->for($user);

if ($inVariantA) {
    // Show variant A
} else {
    // Show variant B (control)
}

// Gradual UI rollout phases
// Phase 1: Internal testing (10%)
Toggl::rollout('new-ui')
    ->toPercentage(10)
    ->withStickiness(true)
    ->withSeed('ui-v2')
    ->for($user);

// Phase 2: Early adopters (25%)
Toggl::rollout('new-ui')
    ->toPercentage(25)
    ->withStickiness(true)
    ->withSeed('ui-v2')
    ->for($user);

// Phase 3: General availability (100%)
Toggl::rollout('new-ui')
    ->toPercentage(100)
    ->for($user);
```

**Key Features:**
- `toPercentage($percent)` - Set rollout percentage (0-100)
- `withStickiness($bool)` - Enable consistent user assignment (default: true)
- `withSeed($seed)` - Custom seed for deterministic hashing
- Sticky rollouts maintain same users as percentage increases
- Consistent hashing ensures predictable user assignment
- Supports objects with `id` property, strings, or numeric contexts

**Use Cases:**
- Gradual new feature rollouts
- Canary deployments (1-5% of users)
- A/B testing and experiments
- Beta program enrollments
- Risk mitigation for new features
- Emergency rollbacks (set to 0%)
- Phased migrations
- Infrastructure capacity testing

## Dependency Conductor

Manage feature dependencies and prerequisites:

```php
// Require database-v2 before enabling reporting
Toggl::dependency('database-v2')
    ->before('advanced-reporting')
    ->for($user);

// Require multiple prerequisites
Toggl::dependency(['api-v2', 'auth-service'])
    ->before('new-dashboard')
    ->for($user);

// Single prerequisite (string or array)
Toggl::dependency('payment-gateway')
    ->before('checkout')
    ->for($user);

// Complex dependency chain
// Step 1: Ensure API v2 is active
Toggl::for($user)->activate('api-v2');

// Step 2: Activate features that depend on API v2
Toggl::dependency('api-v2')
    ->before('real-time-notifications')
    ->for($user);

Toggl::dependency('api-v2')
    ->before('websocket-support')
    ->for($user);

// Step 3: Activate features requiring multiple dependencies
Toggl::dependency(['api-v2', 'websocket-support'])
    ->before('live-collaboration')
    ->for($user);

// Beta program with prerequisites
Toggl::for($user)->activate('beta-enrollment');
Toggl::for($user)->activate('feature-flags-v2');

Toggl::dependency(['beta-enrollment', 'feature-flags-v2'])
    ->before('experimental-features')
    ->for($user);

// Service-based dependencies
Toggl::dependency('payment-processor')
    ->before('subscription-management')
    ->for($organization);

Toggl::dependency('email-service')
    ->before('notification-center')
    ->for($organization);

// Microservice dependencies
Toggl::dependency(['user-service', 'auth-service'])
    ->before('single-sign-on')
    ->for($tenant);
```

**Key Features:**
- `dependency($prerequisites)` - Define required feature(s)
- `before($feature)` - Specify dependent feature
- `for($context)` - Enforce dependencies (throws if not met)
- Supports single or multiple prerequisites
- Throws RuntimeException if prerequisites missing
- Ensures features activate in correct order

**Use Cases:**
- Feature activation ordering
- Service dependency management
- Microservice prerequisites
- Infrastructure readiness checks
- Beta program gating
- API versioning dependencies
- Module loading sequences
- Configuration prerequisites

## Strategy Conductor

Unified API for multiple feature activation strategies.

Gradual feature rollouts with percentage-based activation:

```php
use Cline\Toggl\Toggl;

// Percentage-based rollout (roll out to 25% of users)
Toggl::strategy('new-ui')
    ->percentage(25)
    ->for($user);

// Increase to 50%
Toggl::strategy('new-ui')
    ->percentage(50)
    ->for($user);

// Full rollout
Toggl::strategy('new-ui')
    ->percentage(100)
    ->for($user);
```

Time-based feature activation:

```php
// Activate from specific date
Toggl::strategy('holiday-theme')
    ->from('2024-12-01')
    ->for($user);

// Activate for date range
Toggl::strategy('summer-sale')
    ->from('2024-06-01')
    ->until('2024-08-31')
    ->for($user);

// Global time-based activation (no context needed)
Toggl::strategy('maintenance-mode')
    ->from('2024-01-15')
    ->until('2024-01-16')
    ->activate();
```

Variant distribution:

```php
// Define and assign variants in one step
Toggl::strategy('checkout-flow')
    ->variants([
        'original' => 50,
        'simplified' => 50,
    ])
    ->for($user);

// Multi-variate test
Toggl::strategy('pricing-experiment')
    ->variants([
        'basic' => 25,
        'pro' => 50,
        'enterprise' => 25,
    ])
    ->for($user);
```

**Key Features:**
- `percentage($percent)` - Percentage-based rollout (0-100)
- `from($date)` - Start date for time-based activation
- `until($date)` - End date for time-based activation
- `variants($weights)` - Variant distribution (weights must sum to 100)
- `for($context)` - Apply strategy to context (terminal method)
- `activate()` - Apply global time-based strategy (terminal method)

**Use Cases:**
- Progressive feature rollouts
- Seasonal feature activation
- Time-limited promotions
- A/B testing setup
- Canary deployments

## Pipeline Conductor

Execute multiple feature operations in sequence for a context.

Basic pipeline operations:

```php
use Cline\Toggl\Toggl;

// Chain multiple activations and deactivations
Toggl::pipeline()
    ->activate('premium-dashboard')
    ->activate('advanced-analytics')
    ->deactivate('trial-banner')
    ->for($user);

// Activate multiple features at once
Toggl::pipeline()
    ->activate(['feat-1', 'feat-2', 'feat-3'])
    ->deactivate(['old-feat-1', 'old-feat-2'])
    ->for($user);
```

Pipeline with side effects using tap:

```php
// Execute callbacks between operations
Toggl::pipeline()
    ->activate('premium')
    ->tap(fn($context) => Log::info("Activated premium for {$context->email}"))
    ->activate('analytics')
    ->tap(fn($context) => Cache::forget("user-{$context->id}-features"))
    ->deactivate('trial')
    ->for($user);

// Complex upgrade flow
Toggl::pipeline()
    ->deactivate('free-tier')
    ->tap(fn($user) => event(new UpgradeStarted($user)))
    ->activate(['premium-dashboard', 'priority-support', 'advanced-features'])
    ->tap(fn($user) => event(new UpgradeCompleted($user)))
    ->tap(fn($user) => Mail::to($user)->send(new WelcomeToPremium()))
    ->for($user);
```

**Key Features:**
- `activate($features)` - Add activation operation (single or array)
- `deactivate($features)` - Add deactivation operation (single or array)
- `tap($callback)` - Execute side effect callback receiving context
- `for($context)` - Execute all pipeline operations (terminal method)

**Use Cases:**
- User onboarding flows
- Subscription upgrades/downgrades
- Feature migrations
- Bulk feature management
- Coordinated feature changes with logging

## Cascade Conductor

Activate or deactivate a feature along with all its dependent features.

Cascade activation (primary feature + dependents):

```php
use Cline\Toggl\Toggl;

// Activate premium tier with all dependent features
Toggl::cascade('premium-tier')
    ->activating([
        'premium-dashboard',
        'advanced-analytics',
        'priority-support',
        'api-access',
    ])
    ->for($user);

// Activate enterprise suite with dependencies
Toggl::cascade('enterprise')
    ->activating([
        'sso-authentication',
        'audit-logging',
        'custom-branding',
        'dedicated-support',
        'advanced-security',
    ])
    ->for($organization);
```

Cascade deactivation (dependents first, then primary):

```php
// Downgrade: deactivate premium features then tier
Toggl::cascade('premium-tier')
    ->deactivating([
        'advanced-analytics',
        'priority-support',
        'api-access',
    ])
    ->for($user);

// Remove feature bundle
Toggl::cascade('beta-program')
    ->deactivating([
        'experimental-ui',
        'advanced-features',
        'early-access',
    ])
    ->for($user);
```

**Key Features:**
- `activating($features)` - Activate primary then dependent features
- `deactivating($features)` - Deactivate dependents then primary
- `for($context)` - Execute cascade operation (terminal method)

**Operation Order:**
- Activation: Primary feature first, then dependents
- Deactivation: Dependents first, then primary feature

**Use Cases:**
- Tier-based feature bundles
- Feature package upgrades/downgrades
- Coordinated feature rollouts
- Subscription plan changes
- Access level management

## Variant Conductor

A/B testing and feature variants with weight-based distribution.

```php
use Cline\Toggl\Toggl;

// Get variant for user (weight-based distribution)
$result = Toggl::variant('checkout-flow')->for($user);
$variant = $result->get(); // 'original' or 'simplified'

// Check which variant is assigned
if ($result->is('simplified')) {
    // Show simplified checkout
}

// Get variant with default fallback
$color = Toggl::variant('button-color')->for($user)->getOr('blue');

// Assign specific variant (override distribution)
Toggl::variant('checkout-flow')->use('simplified')->for($user);
```

Multi-variate testing:

```php
// Define variant weights (in FeatureManager configuration)
Toggl::defineVariant('pricing-tier', [
    'basic' => 25,
    'pro' => 50,
    'enterprise' => 25,
]);

// Get assigned variant
$tier = Toggl::variant('pricing-tier')->for($user)->get();

// Gradual rollout example
Toggl::defineVariant('new-ui', [
    'old' => 90,
    'new' => 10,  // 10% get new UI
]);

$uiVersion = Toggl::variant('new-ui')->for($user)->get();
```

Force specific variants for testing:

```php
// Override weight distribution for specific users
Toggl::variant('experiment')->use('variant-b')->for($testUser);

// Verify assignment
expect(Toggl::variant('experiment')->for($testUser)->is('variant-b'))->toBeTrue();

// Internal testing scenario
if ($user->isInternalTester()) {
    Toggl::variant('beta-features')->use('enabled')->for($user);
}
```

**Key Features:**
- `use($variant)` - Assign specific variant (override distribution)
- `for($context)` - Apply variant to context, returns VariantResult (terminal method)
- `get()` - Get assigned variant name (on VariantResult)
- `is($name)` - Check if specific variant is assigned (on VariantResult)
- `getOr($default)` - Get variant or default value (on VariantResult)

**How It Works:**
- Uses CRC32 hashing for consistent assignment
- Same feature+context always gets same variant
- Weights determine distribution percentages
- `use()` overrides weight-based assignment

**Use Cases:**
- A/B testing UI changes
- Gradual feature rollouts
- Multi-variate experiments
- Algorithmic testing
- Price testing
- Internal testing overrides

### Variants (A/B Testing)

Create A/B tests and multivariate experiments with weight-based distribution:

```php
// Define variant with weights (must sum to 100)
Toggl::defineVariant('checkout-flow', [
    'original' => 50,
    'simplified' => 50,
]);

// Get assigned variant (consistent per context)
$variant = Toggl::variant('checkout-flow')->for($user)->get();
// Returns: 'original' or 'simplified'

// Check which variant is assigned
$result = Toggl::variant('checkout-flow')->for($user);
if ($result->is('simplified')) {
    // Show simplified checkout
}

// Get variant or default
$color = Toggl::variant('button-color')->for($user)->getOr('blue');

// Assign specific variant (override distribution)
Toggl::variant('checkout-flow')->use('simplified')->for($user);
$variant = Toggl::variant('checkout-flow')->for($user)->get();
// Always returns: 'simplified'

// Multi-variate test
Toggl::defineVariant('pricing-tier', [
    'basic' => 25,
    'pro' => 50,
    'enterprise' => 25,
]);

$tier = Toggl::variant('pricing-tier')->for($user)->get();

// Gradual rollout (10% get new UI)
Toggl::defineVariant('new-ui', [
    'old' => 90,
    'new' => 10,
]);

// Force variant for testing
Toggl::variant('new-feature')->use('on')->for($testUser);
expect(Toggl::variant('new-feature')->for($testUser)->is('on'))->toBeTrue();
```

**How it works:**
- Variants use CRC32 hashing for consistent assignment
- Same feature+context always gets same variant
- Weights determine distribution percentages
- `use()` overrides weight-based assignment

### Conditional Activation

Activate features only when conditions are met:

```php
// Activate only if condition is true
Toggl::activate('admin-panel')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->for($user);

// Activate unless condition is true
Toggl::activate('trial-banner')
    ->unless(fn($user) => $user->subscribed)
    ->for($user);

// Chain multiple conditions (AND logic)
Toggl::activate('enterprise-suite')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->onlyIf(fn($user) => $user->verified)
    ->for($user);

// Mix onlyIf and unless
Toggl::activate('advanced-features')
    ->onlyIf(fn($user) => $user->role === 'admin')
    ->unless(fn($user) => $user->banned)
    ->for($user);

// Works with values too
Toggl::activate('theme')
    ->withValue('dark-pro')
    ->onlyIf(fn($user) => $user->subscription === 'pro')
    ->for($user);

// Subscription-based access
Toggl::activate('premium-features')
    ->onlyIf(fn($user) => in_array($user->subscription, ['pro', 'enterprise']))
    ->for($user);
```

**How it works:**
- Conditions execute in order, short-circuiting on first failure
- `onlyIf()` must evaluate to `true` for activation to proceed
- `unless()` must evaluate to `false` for activation to proceed
- All conditions must pass for feature to activate
- If any condition fails, feature remains inactive

### Everyone Activation/Deactivation

```php
// Activate for all contexts
Toggl::activateForEveryone('new-dashboard');

// Alias: enableGlobally (more concise)
Toggl::enableGlobally('new-dashboard');

// Activate with value for everyone
Toggl::activateForEveryone('api-version', 'v2');
Toggl::enableGlobally('api-version', 'v2'); // Alias

// Deactivate for all contexts
Toggl::deactivateForEveryone('maintenance-mode');

// Alias: disableGlobally
Toggl::disableGlobally('maintenance-mode');

// Works with arrays too
Toggl::activateForEveryone(['feature-1', 'feature-2']);
Toggl::enableGlobally(['feature-1', 'feature-2']); // Alias

Toggl::deactivateForEveryone(['old-feature-1', 'old-feature-2']);
Toggl::disableGlobally(['old-feature-1', 'old-feature-2']); // Alias
```

## Inherit Conductor

Context scope inheritance where child contexts inherit features from parent contexts.

```php
use Cline\Toggl\Toggl;

// User inherits all team features
Toggl::inherit($user)->from($team);

// Organization → Team → User cascade
Toggl::inherit($team)->from($organization);
Toggl::inherit($user)->from($team);
```

**Child Precedence**: Child's own settings always take precedence over inherited features.

```php
// Team has dark theme
Toggl::for($team)->activate('theme', 'dark');

// User has their own theme preference
Toggl::for($user)->activate('theme', 'light');

// Inheritance doesn't override user's preference
Toggl::inherit($user)->from($team);

expect(Toggl::for($user)->value('theme'))->toBe('light'); // User's value preserved
```

**Selective Inheritance** with `only()`:

```php
// Inherit only specific features
Toggl::inherit($user)
    ->only(['advanced-analytics', 'priority-support'])
    ->from($premiumTemplate);

// User gets only specified features from template
expect(Toggl::for($user)->active('advanced-analytics'))->toBeTrue();
expect(Toggl::for($user)->active('priority-support'))->toBeTrue();
```

**Exclude Features** with `except()`:

```php
// Inherit all except admin features
Toggl::inherit($user)
    ->except(['admin-panel', 'user-management'])
    ->from($organization);

// User gets all organization features except excluded ones
expect(Toggl::for($user)->active('premium'))->toBeTrue();
expect(Toggl::for($user)->active('admin-panel'))->toBeFalse();
```

**Key Features:**
- `only($features)` - Inherit only specified features (whitelist)
- `except($features)` - Inherit all except specified features (blacklist)
- `from($parentContext)` - Execute inheritance from parent context (terminal method)
- Child settings always take precedence over parent
- Supports cascading multi-level inheritance

**Use Cases:**
- User inheriting team/organization features
- Template-based feature assignment
- Role-based feature inheritance
- Multi-tenant feature hierarchies
- Subscription tier inheritance
- Department/group feature propagation

## Observe Conductor

Monitor feature changes and execute callbacks when features are activated, deactivated, or changed.

```php
use Cline\Toggl\Toggl;

// Create observer and check for changes
$observer = Toggl::observe('premium')
    ->onActivate(function ($feature, $value) {
        Log::info("Feature {$feature} activated with value {$value}");
    })
    ->for($user);

// Later, check for changes
Toggl::for($user)->activate('premium');
$observer->check(); // Triggers onActivate callback
```

**Callback Types:**

```php
// onChange - fires on any state or value change
$observer = Toggl::observe('theme')
    ->onChange(function ($feature, $oldValue, $newValue, $isActive) {
        Log::info("Theme changed from {$oldValue} to {$newValue}");
    })
    ->for($user);

// onActivate - fires when feature is activated
$observer = Toggl::observe('premium')
    ->onActivate(function ($feature, $value) {
        Mail::send(new PremiumActivatedEmail($user));
    })
    ->for($user);

// onDeactivate - fires when feature is deactivated
$observer = Toggl::observe('premium')
    ->onDeactivate(function ($feature, $oldValue) {
        Log::warning("Premium downgraded, was: {$oldValue}");
    })
    ->for($user);
```

**Callback Precedence:** Specific callbacks (onActivate/onDeactivate) take precedence over the general onChange callback.

**Observer State Tracking:**

```php
$observer = Toggl::observe('premium')->for($user);

// Query current state
if ($observer->isActive()) {
    $currentValue = $observer->value();
}

// Check for changes
$observer->check(); // Only fires callbacks if state changed
$observer->check(); // Won't fire again without new changes
```

**Chaining Multiple Callbacks:**

```php
$observer = Toggl::observe('premium')
    ->onActivate(function () {
        Metrics::increment('premium_activations');
    })
    ->onDeactivate(function () {
        Metrics::increment('premium_cancellations');
    })
    ->for($user);
```

**Key Features:**
- `onChange($callback)` - Fire on any state or value change
- `onActivate($callback)` - Fire when feature activates
- `onDeactivate($callback)` - Fire when feature deactivates
- `for($context)` - Create observer for context (terminal method)
- Observer has `check()` method to detect changes
- Observer has `isActive()` and `value()` to query state
- Callbacks only fire once per change
- Specific callbacks override general onChange

**Use Cases:**
- Logging feature activation/deactivation
- Sending notifications on feature changes
- Tracking subscription upgrades/downgrades
- Triggering side effects when features change
- Monitoring A/B test variant assignments
- Auditing feature usage patterns

## Comparison Conductor

Compare feature states between contexts to identify differences, unique features, and value changes.

```php
use Cline\Toggl\Toggl;

// Compare two contexts directly
$diff = Toggl::compare($user1, $user2)->get();

// Or use deferred comparison
$diff = Toggl::compare($user)->against($team);
```

**Comparison Result Structure:**

```php
[
    'only_context1' => ['feature-a' => true, 'feature-b' => 'value'],
    'only_context2' => ['feature-c' => true],
    'different_values' => [
        'theme' => [
            'context1' => 'dark',
            'context2' => 'light',
        ],
    ],
]
```

**Real-World Examples:**

```php
// Compare user against team baseline
$diff = Toggl::compare($user)->against($team);

if (!empty($diff['only_context1'])) {
    // User has custom features not in team baseline
    Log::info('User customizations:', $diff['only_context1']);
}

if (!empty($diff['different_values'])) {
    // User has overridden team defaults
    foreach ($diff['different_values'] as $feature => $values) {
        Log::info("{$feature}: team={$values['context2']}, user={$values['context1']}");
    }
}

// Compare environments for drift detection
$diff = Toggl::compare($production, $staging)->get();

if (!empty($diff['only_context1']) || !empty($diff['only_context2'])) {
    Alert::send('Environment feature drift detected!');
}

// Track subscription tier differences
$basicFeatures = Toggl::for($basicTemplate)->stored();
$premiumFeatures = Toggl::for($premiumTemplate)->stored();
$diff = Toggl::compare($basicTemplate, $premiumTemplate)->get();

// $diff['only_context2'] shows premium-exclusive features
$premiumExclusive = $diff['only_context2'];

// Feature rollout progress
$target = Toggl::for($targetState)->stored();
$current = Toggl::for($currentState)->stored();
$diff = Toggl::compare($current, $target)->get();

$remaining = $diff['only_context2']; // Features still to be rolled out
$progress = count($current) / (count($current) + count($remaining)) * 100;
```

**Key Features:**
- `compare($context1, $context2)` - Compare two contexts directly
- `compare($context1)->against($context2)` - Deferred comparison
- `get()` - Execute comparison (terminal method)
- Returns differences in three categories
- Only compares active features (filters out false/inactive)
- Useful for auditing, drift detection, and synchronization

**Use Cases:**
- Comparing user settings against team defaults
- Detecting environment configuration drift
- Auditing subscription tier differences
- Tracking feature rollout progress
- Finding customizations and overrides
- Identifying missing or extra features
- Synchronization planning

## Conditional Execution

### When Active

Execute code only when a feature is active:

```php
Toggl::when('new-analytics',
    function () {
        // Feature is active - new analytics
        return Analytics::newVersion()->track();
    },
    function () {
        // Feature is inactive - fallback
        return Analytics::legacy()->track();
    }
);

// Without fallback
Toggl::when('send-welcome-email', function () {
    Mail::to($user)->send(new WelcomeEmail());
});

// Conductor pattern (more chainable)
Toggl::when('premium')
    ->for($user)
    ->then(function () {
        // Feature is active for this user
        return view('dashboard.premium');
    })
    ->otherwise(function () {
        // Feature is inactive for this user
        return view('dashboard.basic');
    });

// Without otherwise clause
Toggl::when('send-notification')
    ->for($user)
    ->then(function () {
        Notification::send($user, new FeatureActivated());
    });

// With BackedEnum
Toggl::when(FeatureFlag::PremiumFeatures)
    ->for($user)
    ->then(fn() => $this->showPremiumUI())
    ->otherwise(fn() => $this->showBasicUI());

// With context
Toggl::for($user)->when('premium-dashboard', function () {
    return view('dashboard.premium');
});
```

### Unless Inactive

Execute code only when a feature is inactive:

```php
Toggl::unless('maintenance-mode',
    function () {
        // Not in maintenance - proceed normally
        return $this->processRequest();
    },
    function () {
        // In maintenance mode - show message
        return response()->view('maintenance', [], 503);
    }
);

// Without active callback
Toggl::unless('beta-ui', function () {
    // Show legacy UI when beta is off
    return view('ui.legacy');
});
```

### Practical Examples

```php
// API versioning
$response = Toggl::when('api-v2',
    fn() => ApiV2::process($request),
    fn() => ApiV1::process($request)
);

// Different payment processors
$result = Toggl::for($team)->when('stripe-payments',
    fn() => Stripe::charge($amount),
    fn() => PayPal::charge($amount)
);

// Feature-specific logging
Toggl::when('detailed-logging', function () {
    Log::debug('User action', [
        'user_id' => $user->id,
        'action' => 'purchase',
        'details' => $details,
    ]);
});
```

## Blade Directives

### @feature Directive

```blade
{{-- Simple check --}}
@feature('new-dashboard')
    <div class="new-dashboard">
        <h1>Welcome to the new dashboard!</h1>
    </div>
@else
    <div class="legacy-dashboard">
        <h1>Dashboard</h1>
    </div>
@endfeature

{{-- Check with specific value --}}
@feature('theme', 'dark')
    <link rel="stylesheet" href="/css/dark-theme.css">
@endfeature

{{-- Contextual check --}}
@feature('premium-badge')
    <span class="badge badge-premium">Premium</span>
@endfeature

{{-- Contextual check with explicit context --}}
@feature('premium-badge', null, $user)
    <span class="badge badge-premium">Premium</span>
@endfeature
```

### Positive Check Directives

#### @hasFeature - Check if a single feature is active

```blade
@hasFeature('premium')
    <span class="badge">Premium Member</span>
@endhasFeature
```

```blade
@hasFeature('premium', $user)
    <span class="badge">Premium Member</span>
@endhasFeature
```

#### @hasAnyFeature - Check if any of the given features are active

```blade
@hasAnyFeature(['beta-ui', 'new-ui', 'experimental-ui'])
    <div class="alert alert-info">
        You're using an experimental UI.
        <a href="/feedback">Share feedback</a>
    </div>
@endhasAnyFeature
```

```blade
@hasAnyFeature(['beta-ui', 'new-ui', 'experimental-ui'], $user)
    <div class="alert alert-info">
        You're using an experimental UI.
        <a href="/feedback">Share feedback</a>
    </div>
@endhasAnyFeature
```

#### @hasAllFeatures - Check if all of the given features are active

```blade
@hasAllFeatures(['auth', 'payment', 'shipping'])
    <button class="btn-checkout">Complete Purchase</button>
@else
    <div class="alert alert-warning">
        Some features are unavailable. Please try again later.
    </div>
@endhasAllFeatures
```

```blade
@hasAllFeatures(['auth', 'payment', 'shipping'], $user)
    <button class="btn-checkout">Complete Purchase</button>
@else
    <div class="alert alert-warning">
        Some features are unavailable. Please try again later.
    </div>
@endhasAllFeatures
```

### Negative Check Directives

#### @missingFeature - Check if a single feature is inactive

```blade
@missingFeature('premium')
    <div class="upgrade-prompt">
        <p>Upgrade to Premium for more features!</p>
        <a href="/upgrade" class="btn">Upgrade Now</a>
    </div>
@endmissingFeature
```

```blade
@missingFeature('premium', $user)
    <div class="upgrade-prompt">
        <p>Upgrade to Premium for more features!</p>
        <a href="/upgrade" class="btn">Upgrade Now</a>
    </div>
@endmissingFeature
```

#### @missingAnyFeature - Check if any of the given features are inactive

```blade
@missingAnyFeature(['api-v2', 'webhooks'])
    <div class="alert alert-warning">
        Some advanced features are not yet enabled for your account.
    </div>
@endmissingAnyFeature
```

```blade
@missingAnyFeature(['api-v2', 'webhooks'], $user)
    <div class="alert alert-warning">
        Some advanced features are not yet enabled for your account.
    </div>
@endmissingAnyFeature
```

#### @missingAllFeatures - Check if all of the given features are inactive

```blade
@missingAllFeatures(['premium', 'trial'])
    <div class="free-tier-notice">
        You're on the free tier. Consider upgrading!
    </div>
@endmissingAllFeatures
```

```blade
@missingAllFeatures(['premium', 'trial'], $user)
    <div class="free-tier-notice">
        You're on the free tier. Consider upgrading!
    </div>
@endmissingAllFeatures
```

### Unless Variants (Alternative Naming)

For teams who prefer "unless" wording:

```blade
{{-- Same as @missingFeature --}}
@unlessFeature('maintenance-mode')
    <main>Normal content here</main>
@endunlessFeature

{{-- Same as @missingAnyFeature --}}
@unlessAnyFeature(['api-v2', 'webhooks'])
    <div class="legacy-api-notice">Using legacy API</div>
@endunlessAnyFeature

{{-- Same as @missingAllFeatures --}}
@unlessAllFeatures(['premium', 'trial'])
    <div class="free-tier-notice">Free tier user</div>
@endunlessAllFeatures
```

```blade
@unlessFeature('maintenance-mode', $user)
    <main>Normal content here</main>
@endunlessFeature

@unlessAnyFeature(['api-v2', 'webhooks'], $user)
    <div class="legacy-api-notice">Using legacy API</div>
@endunlessAnyFeature

@unlessAllFeatures(['premium', 'trial'], $user)
    <div class="free-tier-notice">Free tier user</div>
@endunlessAllFeatures
```

### Directive Reference Table

| Directive | Purpose | Example |
|-----------|---------|---------|
| `@feature` | Single feature active (with optional value) | `@feature('premium')`, `@feature('theme', 'dark')`, or `@feature('premium', null, $user)` |
| `@hasFeature` | Single feature active | `@hasFeature('premium')` or `@hasFeature('premium', $user)` |
| `@hasAnyFeature` | Any feature active | `@hasAnyFeature(['a', 'b'])` or `@hasAnyFeature(['a', 'b'], $user)` |
| `@hasAllFeatures` | All features active | `@hasAllFeatures(['a', 'b'])` or `@hasAllFeatures(['a', 'b'], $user)` |
| `@missingFeature` | Single feature inactive | `@missingFeature('premium')` or `@missingFeature('premium', $user)` |
| `@missingAnyFeature` | Any feature inactive | `@missingAnyFeature(['a', 'b'])` or `@missingAnyFeature(['a', 'b'], $user)` |
| `@missingAllFeatures` | All features inactive | `@missingAllFeatures(['a', 'b'])` or `@missingAllFeatures(['a', 'b'], $user)` |
| `@unlessFeature` | Single feature inactive (alias) | `@unlessFeature('maintenance')` or `@unlessFeature('maintenance', $user)` |
| `@unlessAnyFeature` | Any feature inactive (alias) | `@unlessAnyFeature(['a', 'b'])` or `@unlessAnyFeature(['a', 'b'], $user)` |
| `@unlessAllFeatures` | All features inactive (alias) | `@unlessAllFeatures(['a', 'b'])` or `@unlessAllFeatures(['a', 'b'], $user)` |

### Nested Directives

```blade
@hasFeature('premium-access')
    <div class="premium-section">
        <h2>Premium Features</h2>

        @hasFeature('advanced-analytics')
            <div class="analytics-panel">
                <!-- Advanced analytics -->
            </div>
        @endhasFeature

        @hasFeature('priority-support')
            <div class="support-widget">
                <!-- Priority support widget -->
            </div>
        @endhasFeature
    </div>
@else
    @missingFeature('trial')
        <div class="upgrade-prompt">
            <p>Start your free trial today!</p>
        </div>
    @endmissingFeature
@endhasFeature
```

## Managing Features

### List Defined Features

```php
// Get all defined feature names
$features = Toggl::defined();
// ['new-dashboard', 'beta-api', 'premium-features', ...]
```

### Load Features into Memory

```php
// Pre-load specific features (optimization)
Toggl::load(['feature-1', 'feature-2', 'feature-3']);

// Load all defined features
Toggl::loadAll();

// Load only missing features
Toggl::loadMissing(['feature-1', 'feature-2']);
```

### Forget Feature Values

Remove stored values, reverting to the resolver:

```php
// Forget specific feature
Toggl::forget('beta-access');

// Forget multiple features
Toggl::forget(['feature-a', 'feature-b']);

// Forget for specific context
Toggl::for($user)->forget('custom-setting');
```

### Purge Features

Completely remove features from storage:

```php
// Purge specific feature (all contexts)
Toggl::purge('deprecated-feature');

// Purge multiple features
Toggl::purge(['old-feature-1', 'old-feature-2']);

// Purge all features
Toggl::purge();
```

## Working with Stored Features

When using the database driver, you can inspect stored features:

```php
// Get all stored features
$stored = Toggl::stored();
// [
//     ['name' => 'beta-access', 'context' => 'user-123', 'value' => true],
//     ['name' => 'theme', 'context' => 'user-456', 'value' => 'dark'],
//     ...
// ]

// Get all features (defined + stored)
$all = Toggl::all();
```

## Cache Management

Feature values are cached during the request lifecycle for performance. Manually flush the cache when needed:

```php
// Flush all cached feature values
Toggl::flushCache();

// Useful after bulk operations
Toggl::activateForEveryone('new-feature');
Toggl::flushCache(); // Ensure fresh values
```

The cache is automatically flushed:
- Between requests (Laravel Octane support)
- After queue jobs complete
- When changing drivers

## Real-World Examples

### Feature Toggle Pattern

```php
class DashboardController extends Controller
{
    public function index()
    {
        return Toggl::when('react-dashboard',
            fn() => Inertia::render('Dashboard/New'),
            fn() => view('dashboard.blade')
        );
    }
}
```

### Progressive Enhancement

```blade
<div class="search-container">
    <input type="text" name="q" placeholder="Search...">

    @feature('advanced-search')
        <div class="search-filters">
            <select name="category">...</select>
            <input type="date" name="from">
            <input type="date" name="to">
        </div>
    @endfeature

    <button type="submit">Search</button>
</div>
```

### API Versioning

```php
class ApiController extends Controller
{
    public function process(Request $request)
    {
        $version = Toggl::value('api-version');

        return match($version) {
            'v3' => $this->processV3($request),
            'v2' => $this->processV2($request),
            default => $this->processV1($request),
        };
    }
}
```

### User Preferences

```php
// Store user preference
Toggl::for($user)->activate('email-notifications', [
    'marketing' => true,
    'updates' => true,
    'security' => true,
]);

// Retrieve preference
$notifications = Toggl::for($user)->value('email-notifications');
if ($notifications['marketing']) {
    Mail::to($user)->send(new MarketingEmail());
}
```

### Syncing Features (Replace All)

The sync conductor replaces all existing features/groups for a context (similar to Laravel's relationship sync):

```php
// Replace all features for a user
Toggl::sync($user)->features(['premium', 'analytics', 'reports']);
// User now has ONLY these 3 features, all others are removed

// Remove all features
Toggl::sync($user)->features([]);

// Sync with values
Toggl::sync($user)->withValues([
    'theme' => 'dark',
    'language' => 'es',
    'notifications' => ['email' => true, 'sms' => false],
]);

// Sync feature group memberships
Toggl::groups()->define('beta', ['feature-1']);
Toggl::groups()->define('premium', ['feature-2']);

Toggl::sync($user)->groups(['premium']);
// User now belongs to ONLY the premium group
```

**When to use sync:**
- User subscription changes (replace tier-specific features)
- Import user settings from external source
- Reset user to default state
- Batch updates that need clean slate

## Next Steps

- **[Strategies](strategies.md)** - Learn about time-based, percentage, and conditional strategies
- **[Time Bombs](time-bombs.md)** - Set expiration dates on features
- **[Feature Groups](feature-groups.md)** - Manage related features together
- **[Variants](variants.md)** - Implement A/B testing


Toggl supports multiple resolution strategies for feature flags, allowing you to control when and how features are activated.

## Boolean Strategy

The simplest strategy - always returns the same value.

```php
use Cline\Toggl\Toggl;

// Always active
Toggl::define('dark-mode', true);

// Always inactive
Toggl::define('beta-features', false);

// Conditional based on context
Toggl::define('admin-panel', fn($user) => $user->isAdmin());
```

## Time-Based Strategy

Activate features between specific dates/times.

```php
use Cline\Toggl\Toggl;
use Cline\Toggl\Strategies\TimeBasedStrategy;

Toggl::define('holiday-theme')
    ->strategy(new TimeBasedStrategy(
        start: now()->startOfMonth(),
        end: now()->endOfMonth()
    ));

// Or use resolver with time logic
Toggl::define('business-hours', function ($user) {
    $hour = now()->hour;
    return $hour >= 9 && $hour < 17;
});
```

## Percentage Strategy

Gradually roll out features to a percentage of users using consistent hashing.

```php
use Cline\Toggl\Strategies\PercentageStrategy;

// 25% of users
Toggl::define('new-checkout')
    ->strategy(new PercentageStrategy(25));

// Same user always gets same result (sticky)
Toggl::for($user)->active('new-checkout'); // Consistent per user
```

The percentage is calculated using CRC32 hash of the context, ensuring:
- Same user always gets same result
- Distribution matches the specified percentage
- No database lookups needed

## Scheduled Strategy

Schedule features to activate and/or deactivate at specific times.

```php
use Cline\Toggl\Strategies\ScheduledStrategy;

// Feature activates on Black Friday and deactivates after Cyber Monday
Toggl::define('black-friday-sale')
    ->strategy(new ScheduledStrategy(
        activateAt: now()->parse('2025-11-29 00:00:00'),
        deactivateAt: now()->parse('2025-12-02 23:59:59')
    ));

// Feature is already active, deactivates at end of month
Toggl::define('limited-time-offer')
    ->strategy(new ScheduledStrategy(
        deactivateAt: now()->endOfMonth()
    ));

// Feature activates next Monday, never deactivates
Toggl::define('new-feature-launch')
    ->strategy(new ScheduledStrategy(
        activateAt: now()->next('Monday')
    ));

// Weekend feature using custom logic
Toggl::define('weekend-bonus', function () {
    return now()->isWeekend();
});
```

**Parameters:**
- `activateAt` (optional): When the feature becomes active. If null, active immediately.
- `deactivateAt` (optional): When the feature becomes inactive. If null, never deactivates.

## Conditional Strategy

Custom logic for complex scenarios.

```php
use Cline\Toggl\Strategies\ConditionalStrategy;

// Multi-factor decision
Toggl::define('premium-feature')
    ->strategy(new ConditionalStrategy(function ($user) {
        return $user->subscription === 'premium' 
            && $user->email_verified 
            && !$user->suspended;
    }));

// Environment-based
Toggl::define('debug-toolbar', function () {
    return app()->environment('local', 'staging');
});

// Complex business logic
Toggl::define('bulk-discount', function ($order) {
    return $order->items->count() >= 10 
        && $order->total >= 1000 
        && $order->customer->tier === 'wholesale';
});
```

## Combining Strategies

Use dependencies and time bombs to combine strategy behaviors:

```php
// Percentage rollout that expires
Toggl::define('experimental-ui')
    ->strategy(new PercentageStrategy(10))
    ->expiresAt(now()->addWeeks(2));

// Feature that requires another feature
Toggl::define('advanced-reports')
    ->requires('basic-analytics')
    ->strategy(new ConditionalStrategy(fn($user) => $user->isPremium()));
```

## Custom Strategies

Implement the `Strategy` contract:

```php
namespace App\Strategies;

use Cline\Toggl\Contracts\Strategy;

class RegionStrategy implements Strategy
{
    public function __construct(private array $allowedRegions) {}

    public function resolve(mixed $context): bool
    {
        return in_array($context?->region, $this->allowedRegions);
    }
}

// Use it
Toggl::define('eu-features')
    ->strategy(new RegionStrategy(['EU', 'UK']));
```

## Next Steps

- [Time Bombs](time-bombs.md) - Auto-expiring features
- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing


Time bombs are features that automatically expire after a specified date, preventing abandoned feature flags from cluttering your codebase.

## Setting Expiration Dates

### Using expiresAt()

```php
use Cline\Toggl\Toggl;

Toggl::define('black-friday-sale')
    ->expiresAt(now()->parse('2025-12-02 23:59:59'))
    ->resolver(fn($user) => true);

// With BackedEnum
Toggl::define(FeatureFlag::BlackFridaySale)
    ->expiresAt(now()->parse('2025-12-02 23:59:59'))
    ->resolver(fn($user) => true);

// After expiration, the feature returns false regardless of resolver
```

### Using expiresAfter()

```php
Toggl::define('trial-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => $user->isTrialing());

// Relative expiration
Toggl::define('temporary-access')
    ->expiresAfter(hours: 48)
    ->resolver(fn($user) => true);
```

## Checking Expiration

### Is Feature Expired?

```php
if (Toggl::isExpired('black-friday-sale')) {
    // Clean up related code
}

// With BackedEnum
if (Toggl::isExpired(FeatureFlag::BlackFridaySale)) {
    // Clean up related code
}
```

### Get Expiration Date

```php
$expiresAt = Toggl::expiresAt('trial-feature');
// Returns CarbonInterface|null

if ($expiresAt && $expiresAt->isPast()) {
    // Feature has expired
}

// With BackedEnum
$expiresAt = Toggl::expiresAt(FeatureFlag::TrialFeature);
```

### Expiring Soon Warning

```php
// Features expiring within 3 days
$expiring = Toggl::expiringSoon(days: 3);
// Returns array of feature names

// Send alerts
foreach ($expiring as $feature) {
    Log::warning("Feature '{$feature}' expiring soon", [
        'expires_at' => Toggl::expiresAt($feature),
    ]);
}
```

## Automatic Cleanup

### Manual Cleanup

```php
// Remove all expired features from storage
Toggl::purge(); // Removes expired features

// Or specific features
Toggl::forget(['old-feature', 'deprecated-feature']);
```

### Scheduled Cleanup

Add to your scheduler in `app/Console/Kernel.php`:

```php
use Cline\Toggl\Toggl;

protected function schedule(Schedule $schedule): void
{
    // Clean up expired features weekly
    $schedule->call(function () {
        Toggl::purge();
    })->weekly();

    // Warn about expiring features daily
    $schedule->call(function () {
        $expiring = Toggl::expiringSoon(days: 7);
        
        foreach ($expiring as $feature) {
            Log::warning("Feature expiring soon: {$feature}");
        }
    })->daily();
}
```

## Use Cases

### Limited-Time Promotions

```php
Toggl::define('summer-sale-2025')
    ->expiresAt('2025-08-31 23:59:59')
    ->resolver(fn() => true);

if (Toggl::active('summer-sale-2025')) {
    $discount = 0.20; // 20% off
}
```

### Beta Testing Windows

```php
Toggl::define('beta-v2-api')
    ->expiresAfter(days: 90)
    ->resolver(fn($user) => $user->isBetaTester());

// After 90 days, beta access automatically revoked
```

### Temporary Feature Access

```php
Toggl::define('trial-premium-features')
    ->expiresAt($user->trial_ends_at)
    ->resolver(fn($user) => $user->onTrial());
```

### Emergency Toggles

```php
// Quick toggle that auto-expires
Toggl::define('maintenance-bypass')
    ->expiresAfter(hours: 2)
    ->resolver(fn($user) => $user->isAdmin());

// Automatically reverts after 2 hours
```

## Combining with Other Features

### Time Bomb + Dependencies

```php
Toggl::define('base-feature', true);

Toggl::define('experimental-addon')
    ->requires('base-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => true);
```

### Time Bomb + Percentage Rollout

```php
// 10% rollout for 2 weeks
Toggl::define('new-ui-test')
    ->strategy(new PercentageStrategy(10))
    ->expiresAfter(weeks: 2);
```

### Time Bomb + Groups

```php
Toggl::defineGroup('q4-features', [
    'holiday-theme',
    'gift-recommendations',
    'special-pricing',
]);

// All features in group expire together
Toggl::define('holiday-theme')->expiresAt('2025-01-01');
Toggl::define('gift-recommendations')->expiresAt('2025-01-01');
Toggl::define('special-pricing')->expiresAt('2025-01-01');
```

## Best Practices

1. **Always set expiration for temporary features**
   ```php
   // ✅ Good
   Toggl::define('experiment')->expiresAfter(days: 30);
   
   // ❌ Avoid - might forget to clean up
   Toggl::define('experiment', true);
   ```

2. **Monitor expiring features**
   ```php
   // Schedule regular checks
   $expiring = Toggl::expiringSoon(days: 7);
   if (count($expiring) > 0) {
       notify_team($expiring);
   }
   ```

3. **Document why features expire**
   ```php
   // Clear intent
   Toggl::define('promo-code-double-points')
       ->expiresAt('2025-12-31') // End of promotional period
       ->resolver(fn($user) => true);
   ```

## Next Steps

- [Feature Groups](feature-groups.md) - Managing related features
- [Dependencies](dependencies.md) - Feature requirements
- [Advanced Usage](advanced-usage.md) - Commands and automation


Feature groups allow you to manage related features together, enabling bulk operations, membership-based access control, and simplified testing. Groups can be stored either in configuration files (array storage) or in the database for dynamic management.

## Storage Configuration

Choose between array (in-memory) or database storage in `config/toggl.php`:

```php
return [
    // Storage driver for feature groups
    'group_storage' => env('FEATURE_GROUP_STORAGE', 'array'), // 'array' or 'database'
];
```

**Array Storage**: Groups defined in configuration file, loaded at runtime (best for static groups)
**Database Storage**: Groups persisted in database, manageable via API (best for dynamic groups)

## Defining Groups

### In Configuration (Array Storage)

Edit `config/toggl.php`:

```php
return [
    'group_storage' => 'array',

    'groups' => [
        'beta' => [
            'features' => [
                'new-dashboard',
                'advanced-search',
                'ai-recommendations',
            ],
            'description' => 'Beta testing features',
        ],

        'premium' => [
            'features' => [
                'priority-support',
                'advanced-analytics',
                'custom-branding',
            ],
            'description' => 'Premium tier features',
        ],
    ],
];
```

With BackedEnum for type safety:

```php
use App\Enums\FeatureFlag;

return [
    'group_storage' => 'array',

    'groups' => [
        'beta' => [
            'features' => [
                FeatureFlag::NewDashboard->value,
                FeatureFlag::AdvancedSearch->value,
                FeatureFlag::AiRecommendations->value,
            ],
            'description' => 'Beta testing features',
        ],

        'premium' => [
            'features' => [
                FeatureFlag::PrioritySupport->value,
                FeatureFlag::AdvancedAnalytics->value,
                FeatureFlag::CustomBranding->value,
            ],
            'description' => 'Premium tier features',
        ],
    ],
];
```

### Using Database Storage

```php
// config/toggl.php
return [
    'group_storage' => 'database',
];
```

Then define groups programmatically:

```php
use Cline\Toggl\Toggl;

// Fluent API
Toggl::groups()
    ->create('experimental')
    ->with('new-checkout-flow', 'product-recommendations', 'one-click-purchase')
    ->save();

// Direct definition
Toggl::groups()->define('beta', [
    'new-dashboard',
    'advanced-search',
    'ai-recommendations',
]);

// With BackedEnum
Toggl::groups()->define('beta', [
    FeatureFlag::NewDashboard,
    FeatureFlag::AdvancedSearch,
    FeatureFlag::AiRecommendations,
]);
```

## Managing Groups (Database Storage)

### Creating Groups

```php
// Fluent API
Toggl::groups()
    ->create('beta-testers')
    ->with('new-ui', 'dark-mode')
    ->save();

// Direct definition
Toggl::groups()->define('vip', ['exclusive-feature', 'early-access']);
```

### Updating Groups

```php
// Replace all features
Toggl::groups()->update('beta-testers', ['new-ui', 'dark-mode', 'advanced-api']);

// Add features
Toggl::groups()->add('beta-testers', ['feature-x', 'feature-y']);

// Remove features
Toggl::groups()->remove('beta-testers', ['old-feature']);
```

### Deleting Groups

```php
Toggl::groups()->delete('old-group');
```

### Checking Group Existence

```php
if (Toggl::groups()->exists('beta-testers')) {
    // Group exists
}
```

## Feature Group Membership

Groups support membership-based feature inheritance. When you assign users/teams to a group, they automatically inherit access to all features in that group.

### Assigning Contexts to Groups

Toggl offers two fluent APIs for feature group membership, inspired by Warden's conductor pattern:

```php
// Context-first API (recommended - more natural)
Toggl::groups()->for($user)->assign('beta-testers');
Toggl::groups()->for($team)->assign('premium');

// Traditional API (still available)
Toggl::groups()->assign('beta-testers', $user);
Toggl::groups()->assign('premium', $team);

// Assign multiple users at once
$users = User::where('beta_opt_in', true)->get();
Toggl::groups()->assignMany('beta-testers', $users->all());
```

### Checking Membership

```php
// Context-first API (recommended)
if (Toggl::groups()->for($user)->isIn('beta-testers')) {
    // User is a beta tester
}

// Get all groups for a user
$userGroups = Toggl::groups()->for($user)->groups();
// ['beta-testers', 'early-access']

// Traditional API (still available)
if (Toggl::groups()->isInGroup('beta-testers', $user)) {
    // User is a beta tester
}

$userGroups = Toggl::groups()->groupsFor($user);
// ['beta-testers', 'early-access']

// Get all members of a group
$members = Toggl::groups()->members('beta-testers');
```

### Removing from Groups

```php
// Context-first API (recommended)
Toggl::groups()->for($user)->unassign('beta-testers');

// Traditional API (still available)
Toggl::groups()->unassign('beta-testers', $user);

// Remove all members
Toggl::groups()->clearMembers('beta-testers');
```

## Feature Inheritance Through Groups

When a context belongs to a group, they automatically get access to features activated for that group:

```php
// 1. Define a group
Toggl::groups()->define('experimental', ['new-layout', 'dark-mode']);

// 2. Assign users to group
$users = User::whereIn('id', [1, 2, 3, 4, 5])->get();
Toggl::groups()->assignMany('experimental', $users->all());

// 3. Activate features for the group (using __all__ context)
Toggl::for('__all__')->activate('new-layout');
Toggl::for('__all__')->activate('dark-mode');

// 4. Check feature for user - automatically active via feature group membership!
Toggl::for($users->first())->active('new-layout'); // true
```

**How it works:**
1. User is assigned to group via membership system
2. Feature is activated for `__all__` context (group-level activation)
3. When checking if feature is active for user, Toggl checks:
   - Is feature directly active for this user? If yes, return true
   - If no, check all groups user belongs to
   - For each group, check if feature is in that group's feature list
   - If yes, check if feature is active for `__all__` context
   - If active for `__all__`, user inherits it through feature group membership

## Bulk Operations

### Activate Entire Group

```php
// Traditional context-first pattern
Toggl::for($user)->activateGroup('premium');

// Conductor pattern (group-first) - more natural for bulk operations
Toggl::activateGroupConductor('premium')->for($user);

// Activate for multiple contexts using conductor
Toggl::activateGroupConductor('premium')->for([$user1, $user2, $user3]);

// Both patterns work identically - choose what reads better
```

### Deactivate Entire Group

```php
// Traditional context-first pattern
Toggl::for($user)->deactivateGroup('premium');

// Conductor pattern (group-first)
Toggl::deactivateGroupConductor('premium')->for($user);

// Deactivate for multiple contexts
Toggl::deactivateGroupConductor('beta')->for([$user1, $user2]);
```

## Checking Group Status

### All Features Active

```php
// Check if all features in group are active
if (Toggl::for($user)->activeInGroup('premium')) {
    // User has all premium features
    return view('dashboard.premium');
}
```

### Any Feature Active

```php
// Check if any feature in group is active
if (Toggl::for($user)->someActiveInGroup('beta')) {
    // User has at least one beta feature
    $this->showBetaBadge();
}
```

## Real-World Use Cases

### Beta Program with Membership

```php
// 1. Define beta group
Toggl::groups()->define('beta-program', [
    'new-ui',
    'advanced-filters',
    'bulk-operations',
]);

// 2. Enroll users who opted in
$betaUsers = User::where('beta_opt_in', true)->get();
Toggl::groups()->assignMany('beta-program', $betaUsers->all());

// 3. Activate beta features for all beta group members
Toggl::for('__all__')->activate('new-ui');
Toggl::for('__all__')->activate('advanced-filters');
Toggl::for('__all__')->activate('bulk-operations');

// 4. Check if user sees beta features (via feature group membership)
if (Toggl::for($user)->active('new-ui')) {
    return view('beta.dashboard');
}

// 5. Remove user from beta
Toggl::groups()->unassign('beta-program', $user);
```

### Gradual Rollout with Random Selection

```php
// Select 10 random users for experimental features
$experimentalUsers = User::inRandomOrder()->limit(10)->get();

// Define experimental group
Toggl::groups()->define('experimental', ['new-checkout', 'ai-recommendations']);

// Assign to group
Toggl::groups()->assignMany('experimental', $experimentalUsers->all());

// Activate features for group
Toggl::for('__all__')->activate('new-checkout');
Toggl::for('__all__')->activate('ai-recommendations');

// These 10 users automatically see the features!
foreach ($experimentalUsers as $user) {
    Toggl::for($user)->active('new-checkout'); // true
}

// Others don't
$regularUser = User::where('id', '>', 10)->first();
Toggl::for($regularUser)->active('new-checkout'); // false
```

### Subscription Tiers with Dynamic Assignment

```php
// Define tier groups
Toggl::groups()->define('basic', ['core-features']);
Toggl::groups()->define('pro', ['core-features', 'advanced-analytics', 'api-access']);
Toggl::groups()->define('enterprise', ['core-features', 'advanced-analytics', 'api-access', 'sso', 'custom-branding']);

// Activate all tier features for __all__ context
Toggl::for('__all__')->activate('core-features');
Toggl::for('__all__')->activate('advanced-analytics');
Toggl::for('__all__')->activate('api-access');
Toggl::for('__all__')->activate('sso');
Toggl::for('__all__')->activate('custom-branding');

// Assign user to appropriate tier group based on subscription
match($user->subscription_tier) {
    'basic' => Toggl::groups()->assign('basic', $user),
    'pro' => Toggl::groups()->assign('pro', $user),
    'enterprise' => Toggl::groups()->assign('enterprise', $user),
};

// User automatically inherits features from their tier
Toggl::for($enterpriseUser)->active('sso'); // true
Toggl::for($basicUser)->active('sso'); // false

// When user upgrades
Toggl::groups()->unassign('basic', $user);
Toggl::groups()->assign('pro', $user);
// Now has pro features automatically
```

### Platform-Specific Features

```php
Toggl::defineGroup('mobile', [
    'push-notifications',
    'offline-sync',
    'biometric-auth',
]);

Toggl::defineGroup('desktop', [
    'keyboard-shortcuts',
    'multi-window',
    'system-tray',
]);

// Activate based on platform
if ($request->userAgent()->isMobile()) {
    Toggl::for($user)->activateGroup('mobile');
} else {
    Toggl::for($user)->activateGroup('desktop');
}
```

### Feature Releases

```php
// Q1 2025 features
Toggl::defineGroup('q1-2025', [
    'dark-mode',
    'export-improvements',
    'team-collaboration',
]);

// Enable all at once when ready
Toggl::activateForEveryone('q1-2025');

// Or gradual rollout
$percentage = 25; // 25% of users
Toggl::define('q1-2025-rollout')
    ->strategy(new PercentageStrategy($percentage));

if (Toggl::for($user)->active('q1-2025-rollout')) {
    Toggl::for($user)->activateGroup('q1-2025');
}
```

### Testing Scenarios

```php
// Enable all experimental features for testing
public function setUp(): void
{
    parent::setUp();
    
    Toggl::activateGroup('experimental');
}

// Test specific group combinations
test('premium features work together', function () {
    Toggl::for($user)->activateGroup('premium');
    
    expect(Toggl::for($user)->activeInGroup('premium'))->toBeTrue();
    // Test premium functionality
});
```

## Retrieving Groups and Features

```php
// Get all defined groups
$groups = Toggl::allGroups();
// ['beta' => [...], 'premium' => [...]]

// Get features in a specific group
$betaFeatures = Toggl::getGroup('beta');
// ['new-dashboard', 'advanced-search', 'ai-recommendations']

// Get all groups with database storage
$allGroups = Toggl::groups()->all();
// ['beta' => ['feat1', 'feat2'], 'premium' => ['feat3']]
```

## Combining with Other Features

### Groups + Time Bombs

```php
Toggl::groups()->define('holiday-2025', [
    'gift-wrap-option',
    'holiday-theme',
    'special-discounts',
]);

// Set expiration on all features
foreach (Toggl::getGroup('holiday-2025') as $feature) {
    Toggl::define($feature)
        ->expiresAt('2025-12-26')
        ->resolver(fn() => true);
}
```

### Groups + Dependencies

```php
// Base features required for advanced group
Toggl::define('advanced-analytics')
    ->requires('basic-analytics');

Toggl::groups()->define('analytics-suite', [
    'basic-analytics',
    'advanced-analytics', // Will check dependency
    'custom-reports',
]);
```

## Blade Directives

```blade
@featureall('premium')
    <x-premium-dashboard />
@endfeatureall

@featureany('beta-program')
    <div class="beta-badge">Beta Tester</div>
@endfeatureany
```

## Best Practices

1. **Group by logical boundaries**
   ```php
   // ✅ Good - clear purpose
   Toggl::groups()->define('mobile-app-v2', [...]);

   // ❌ Avoid - too vague
   Toggl::groups()->define('stuff', [...]);
   ```

2. **Keep groups focused**
   ```php
   // ✅ Good - 3-8 related features
   Toggl::groups()->define('search-improvements', [
       'fuzzy-search',
       'search-suggestions',
       'search-history',
   ]);

   // ❌ Avoid - too many unrelated features
   Toggl::groups()->define('everything', [/* 50 features */]);
   ```

3. **Document group purpose**
   ```php
   // config/toggl.php
   'groups' => [
       // Features for Q1 2025 release
       'q1-2025' => [
           'features' => [...],
           'description' => 'Q1 2025 release features',
       ],

       // Beta tester access
       'beta-program' => [
           'features' => [...],
           'description' => 'Beta tester access',
       ],
   ],
   ```

4. **Choose appropriate storage**
   ```php
   // ✅ Use array storage for static, rarely-changing groups
   'group_storage' => 'array',

   // ✅ Use database storage for dynamic groups that change frequently
   'group_storage' => 'database',
   ```

5. **Choose between per-user flags vs feature group membership**
   ```php
   // ✅ Per-user flags for individual access (subscriptions, permissions)
   Toggl::for($user)->activate('premium-features');

   // ✅ Feature group membership for cohorts (beta testers, experimental rollouts)
   Toggl::groups()->assign('beta', $user);
   Toggl::for('__all__')->activate('beta-feature');
   ```

6. **Clean up memberships when users leave groups**
   ```php
   // When user cancels subscription
   Toggl::groups()->unassign('premium', $user);

   // When beta program ends
   Toggl::groups()->clearMembers('beta-program');
   ```

## Next Steps

- [Dependencies](dependencies.md) - Feature requirements
- [Variants](variants.md) - A/B testing
- [Advanced Usage](advanced-usage.md) - Automation and commands


Feature dependencies allow you to create relationships between features, ensuring that advanced features are only active when their prerequisites are met.

## Defining Dependencies

### Single Dependency

```php
use Cline\Toggl\Toggl;

Toggl::define('basic-analytics', fn($user) => $user->hasSubscription());

Toggl::define('advanced-analytics')
    ->requires('basic-analytics')
    ->resolver(fn($user) => $user->subscription === 'premium');

// With BackedEnum
Toggl::define(FeatureFlag::AdvancedAnalytics)
    ->requires(FeatureFlag::BasicAnalytics)
    ->resolver(fn($user) => $user->subscription === 'premium');
```

If `basic-analytics` is inactive, `advanced-analytics` will automatically return `false`, even if its resolver returns `true`.

### Multiple Dependencies

```php
Toggl::define('team-collaboration')
    ->requires('user-management', 'real-time-sync')
    ->resolver(fn($team) => $team->size >= 5);

// With BackedEnum (mixed or all enums)
Toggl::define(FeatureFlag::TeamCollaboration)
    ->requires(FeatureFlag::UserManagement, FeatureFlag::RealtimeSync)
    ->resolver(fn($team) => $team->size >= 5);
```

All dependencies must be active for the feature to be active.

## Checking Dependencies

### Get Dependencies

```php
$deps = Toggl::getDependencies('advanced-analytics');
// ['basic-analytics']

$deps = Toggl::getDependencies('team-collaboration');
// ['user-management', 'real-time-sync']
```

### Check if Dependencies are Met

```php
if (Toggl::dependenciesMet('advanced-analytics')) {
    // All dependencies are active
}

// With BackedEnum
if (Toggl::dependenciesMet(FeatureFlag::AdvancedAnalytics)) {
    // All dependencies are active
}
```

## Transitive Dependencies

Dependencies are checked recursively:

```php
Toggl::define('level-1', true);

Toggl::define('level-2')
    ->requires('level-1')
    ->resolver(fn() => true);

Toggl::define('level-3')
    ->requires('level-2')
    ->resolver(fn() => true);

// level-3 checks: level-2 → level-1
Toggl::active('level-3'); // true only if all levels active
```

## Circular Dependency Protection

Toggl detects and prevents circular dependencies:

```php
Toggl::define('feature-a')
    ->requires('feature-b')
    ->resolver(fn() => true);

Toggl::define('feature-b')
    ->requires('feature-a')
    ->resolver(fn() => true);

// Both return false - circular dependency detected
Toggl::active('feature-a'); // false
Toggl::active('feature-b'); // false
```

## Use Cases

### Feature Tiers

```php
// Basic → Pro → Enterprise scope
Toggl::define('basic-features', fn($user) => $user->hasAnySubscription());

Toggl::define('pro-features')
    ->requires('basic-features')
    ->resolver(fn($user) => in_array($user->plan, ['pro', 'enterprise']));

Toggl::define('enterprise-features')
    ->requires('pro-features')
    ->resolver(fn($user) => $user->plan === 'enterprise');
```

### Progressive Feature Unlocking

```php
// Tutorial completion required
Toggl::define('advanced-tools')
    ->requires('tutorial-completed')
    ->resolver(fn($user) => $user->level >= 5);

Toggl::define('expert-mode')
    ->requires('advanced-tools')
    ->resolver(fn($user) => $user->level >= 10);
```

### Platform Capabilities

```php
Toggl::define('offline-mode', fn() => true);

Toggl::define('background-sync')
    ->requires('offline-mode')
    ->resolver(fn($device) => $device->hasNetwork());

Toggl::define('real-time-collaboration')
    ->requires('background-sync')
    ->resolver(fn($user) => $user->isPremium());
```

### API Versioning

```php
Toggl::define('api-v1', fn() => true);

Toggl::define('api-v2')
    ->requires('api-v1')
    ->resolver(fn($client) => $client->hasOptedIn());

Toggl::define('api-v3')
    ->requires('api-v2')
    ->resolver(fn($client) => $client->isEarlyAdopter());
```

## Contextual Dependencies

Dependencies work with contextual features:

```php
Toggl::define('team-features', fn($user) => $user->hasTeam());

Toggl::define('team-admin-panel')
    ->requires('team-features')
    ->resolver(fn($user) => $user->isTeamAdmin());

// Check for specific user
Toggl::for($user)->active('team-admin-panel');
// Returns true only if:
// 1. User has a team (team-features)
// 2. User is team admin (team-admin-panel resolver)
```

## Combining with Other Features

### Dependencies + Time Bombs

```php
Toggl::define('base-feature', fn() => true);

Toggl::define('experimental-addon')
    ->requires('base-feature')
    ->expiresAfter(days: 30)
    ->resolver(fn($user) => $user->isBetaTester());
```

### Dependencies + Groups

```php
Toggl::defineGroup('advanced-suite', [
    'advanced-analytics',
    'custom-reports',
    'api-access',
]);

// Each feature in group has same dependency
Toggl::define('advanced-analytics')->requires('premium-subscription');
Toggl::define('custom-reports')->requires('premium-subscription');
Toggl::define('api-access')->requires('premium-subscription');

// Activate group only if dependency is met
if (Toggl::for($user)->active('premium-subscription')) {
    Toggl::for($user)->activateGroup('advanced-suite');
}
```

### Dependencies + Percentage Rollout

```php
Toggl::define('new-ui', fn() => true);

// Gradual rollout of advanced features requiring new UI
Toggl::define('advanced-ui-features')
    ->requires('new-ui')
    ->strategy(new PercentageStrategy(25));
```

## Manual Override

You can still manually activate a feature even if dependencies aren't met, but it will still check dependencies when evaluated:

```php
Toggl::define('dependency', false);

Toggl::define('dependent')
    ->requires('dependency')
    ->resolver(fn() => true);

// Manually activate
Toggl::activate('dependent');

// Still returns false because dependency not met
Toggl::active('dependent'); // false

// Activate dependency
Toggl::activate('dependency');

// Now it works
Toggl::active('dependent'); // true
```

## Best Practices

1. **Document dependency chains**
   ```php
   // Clear scope
   Toggl::define('level-1', true); // Base level
   Toggl::define('level-2')->requires('level-1'); // Requires base
   Toggl::define('level-3')->requires('level-2'); // Requires level 2
   ```

2. **Avoid deep chains**
   ```php
   // ✅ Good - 2-3 levels
   basic → advanced → expert
   
   // ❌ Avoid - too complex
   a → b → c → d → e → f
   ```

3. **Use meaningful names**
   ```php
   // ✅ Good
   Toggl::define('sso-integration')
       ->requires('user-authentication');
   
   // ❌ Unclear
   Toggl::define('feature-x')
       ->requires('feature-y');
   ```

4. **Test dependency chains**
   ```php
   test('dependency chain works correctly', function () {
       Toggl::define('base', false);
       Toggl::define('dependent')->requires('base');
       
       expect(Toggl::active('dependent'))->toBeFalse();
       
       Toggl::activate('base');
       expect(Toggl::active('dependent'))->toBeTrue();
   });
   ```

## Next Steps

- [Variants](variants.md) - A/B testing and value variants
- [Advanced Usage](advanced-usage.md) - Events, middleware, and commands


Variants enable A/B testing and multi-variant experiments by returning different values instead of just true/false.

## Defining Variants

### Basic Variant Definition

```php
use Cline\Toggl\Toggl;

Toggl::defineVariant('checkout-flow', [
    'control' => 40,  // 40% of users
    'v1' => 30,       // 30% of users
    'v2' => 30,       // 30% of users
]);

// With BackedEnum
Toggl::defineVariant(FeatureFlag::CheckoutFlow, [
    'control' => 40,
    'v1' => 30,
    'v2' => 30,
]);

// Weights must sum to 100
```

### Retrieving Variants

```php
$variant = Toggl::variant('checkout-flow');
// Returns: 'control', 'v1', or 'v2'

// Contextual to specific user
$variant = Toggl::for($user)->variant('checkout-flow');
// Same user always gets same variant (sticky)

// With BackedEnum
$variant = Toggl::for($user)->variant(FeatureFlag::CheckoutFlow);
```

## How Variants Work

Variants use **consistent hashing (CRC32)** to ensure:
- Same context always gets same variant
- Distribution matches specified weights
- No database lookups required
- Deterministic across requests

```php
// User A always gets 'v1'
Toggl::for($userA)->variant('experiment'); // 'v1'
Toggl::for($userA)->variant('experiment'); // 'v1' (same)

// User B always gets 'control'
Toggl::for($userB)->variant('experiment'); // 'control'
Toggl::for($userB)->variant('experiment'); // 'control' (same)
```

## Use Cases

### A/B Testing

```php
Toggl::defineVariant('pricing-page', [
    'control' => 50,
    'price-first' => 25,
    'features-first' => 25,
]);

$layout = Toggl::for($user)->variant('pricing-page');

return match($layout) {
    'price-first' => view('pricing.price-first'),
    'features-first' => view('pricing.features-first'),
    default => view('pricing.control'),
};
```

### Multi-Variant Experiments

```php
Toggl::defineVariant('button-color', [
    'blue' => 25,
    'green' => 25,
    'red' => 25,
    'orange' => 25,
]);

$buttonColor = Toggl::for($visitor)->variant('button-color');
```

### Gradual Feature Rollout

```php
Toggl::defineVariant('new-editor', [
    'legacy' => 70,   // 70% stay on old
    'new' => 30,      // 30% get new editor
]);

if (Toggl::for($user)->variant('new-editor') === 'new') {
    return $this->newEditor();
}

return $this->legacyEditor();
```

### Algorithm Testing

```php
Toggl::defineVariant('recommendation-algorithm', [
    'collaborative-filtering' => 33,
    'content-based' => 33,
    'hybrid' => 34,
]);

$algorithm = Toggl::for($user)->variant('recommendation-algorithm');

$recommendations = match($algorithm) {
    'collaborative-filtering' => $this->collaborativeFiltering($user),
    'content-based' => $this->contentBased($user),
    'hybrid' => $this->hybrid($user),
};
```

## Checking Variants

### Get Variant Names

```php
$variants = Toggl::variantNames('checkout-flow');
// ['control', 'v1', 'v2']
```

### Get All Variant Configs

```php
$config = Toggl::getVariants('checkout-flow');
// ['control' => 40, 'v1' => 30, 'v2' => 30]
```

### Check if Feature Has Variants

```php
if (Toggl::getVariants('my-feature')) {
    // Feature has variants
    $variant = Toggl::variant('my-feature');
} else {
    // Regular boolean feature
    $active = Toggl::active('my-feature');
}
```

## Updating Variant Weights

```php
// Start with small test
Toggl::defineVariant('new-search', [
    'legacy' => 90,
    'new' => 10,
]);

// Increase after positive results
Toggl::defineVariant('new-search', [
    'legacy' => 50,
    'new' => 50,
]);

// Full rollout
Toggl::defineVariant('new-search', [
    'legacy' => 0,
    'new' => 100,
]);

// Or just switch to boolean
Toggl::define('new-search', true);
```

## Blade Usage

```blade
@php
    $variant = Toggl::for(auth()->user())->variant('landing-page');
@endphp

@if($variant === 'hero-video')
    <x-hero-video />
@elseif($variant === 'hero-carousel')
    <x-hero-carousel />
@else
    <x-hero-static />
@endif
```

## Tracking Variant Performance

```php
$variant = Toggl::for($user)->variant('checkout-flow');

// Track in analytics
Analytics::track('checkout_started', [
    'user_id' => $user->id,
    'variant' => $variant,
]);

// Log conversion
Analytics::track('purchase_completed', [
    'user_id' => $user->id,
    'variant' => $variant,
    'amount' => $order->total,
]);
```

## Combining with Other Features

### Variants + Time Bombs

```php
// Run experiment for 30 days
Toggl::defineVariant('price-test', [
    'control' => 50,
    'higher' => 25,
    'lower' => 25,
])
->expiresAfter(days: 30);

// After expiration, pick winning variant
Toggl::define('price-test', 'lower'); // Winner
```

### Variants + Contexts

```php
// Different experiments per team
$teamVariant = Toggl::for($team)->variant('team-dashboard');

// Different experiments per user
$userVariant = Toggl::for($user)->variant('onboarding-flow');
```

### Variants + Dependencies

```php
Toggl::define('base-feature', true);

// Only run variant test if base feature is active
if (Toggl::active('base-feature')) {
    $variant = Toggl::variant('advanced-test');
}
```

## Best Practices

1. **Keep experiments focused**
   ```php
   // ✅ Good - test one thing
   Toggl::defineVariant('button-text', [
       'buy-now' => 50,
       'purchase' => 50,
   ]);
   
   // ❌ Avoid - too many variables
   Toggl::defineVariant('everything', [
       'variant-a' => 10,
       'variant-b' => 10,
       // ... 8 more variants
   ]);
   ```

2. **Weights must sum to 100**
   ```php
   // ✅ Good
   ['a' => 50, 'b' => 30, 'c' => 20] // = 100
   
   // ❌ Invalid
   ['a' => 50, 'b' => 30, 'c' => 30] // = 110
   ```

3. **Use meaningful variant names**
   ```php
   // ✅ Good
   ['control', 'short-form', 'long-form']
   
   // ❌ Unclear
   ['a', 'b', 'c']
   ```

4. **Track everything**
   ```php
   $variant = Toggl::variant('experiment');
   
   // Log variant assignment
   Log::info('Variant assigned', [
       'feature' => 'experiment',
       'variant' => $variant,
       'user' => $user->id,
   ]);
   ```

5. **Plan your rollout**
   ```php
   // Phase 1: Small test
   Toggl::defineVariant('feature', ['old' => 95, 'new' => 5]);
   
   // Phase 2: Increase if positive
   Toggl::defineVariant('feature', ['old' => 50, 'new' => 50]);
   
   // Phase 3: Full rollout
   Toggl::define('feature', 'new'); // Switch to boolean
   ```

## Testing Variants

```php
test('variant returns consistent results', function () {
    Toggl::defineVariant('test', ['a' => 50, 'b' => 50]);
    
    $user = User::factory()->create();
    
    $variant1 = Toggl::for($user)->variant('test');
    $variant2 = Toggl::for($user)->variant('test');
    
    expect($variant1)->toBe($variant2); // Same user, same variant
    expect($variant1)->toBeIn(['a', 'b']); // Valid variant
});

test('variant distribution is roughly correct', function () {
    Toggl::defineVariant('test', ['a' => 50, 'b' => 50]);
    
    $results = ['a' => 0, 'b' => 0];
    
    for ($i = 0; $i < 1000; $i++) {
        $variant = Toggl::for("user-{$i}")->variant('test');
        $results[$variant]++;
    }
    
    // Should be roughly 50/50 (allowing 10% variance)
    expect($results['a'])->toBeBetween(450, 550);
    expect($results['b'])->toBeBetween(450, 550);
});
```

## Next Steps

- [Advanced Usage](advanced-usage.md) - Events, middleware, and commands
- [Basic Usage](basic-usage.md) - Core operations
- [Strategies](strategies.md) - Different resolution strategies

## Events

Toggl dispatches events that you can listen to for logging, analytics, or custom behavior.

### UnknownFeatureResolved

Triggered when an undefined feature is checked:

```php
use Cline\Toggl\Events\UnknownFeatureResolved;

Event::listen(UnknownFeatureResolved::class, function ($event) {
    Log::warning('Unknown feature accessed', [
        'feature' => $event->feature,
        'context' => $event->context,
    ]);
});
```

### Custom Event Listeners

```php
// In EventServiceProvider
protected $listen = [
    UnknownFeatureResolved::class => [
        LogUnknownToggl::class,
        NotifyTeam::class,
    ],
];
```

## Middleware

### Custom Middleware

```php
namespace App\Http\Middleware;

use Cline\Toggl\Toggl;
use Closure;

class RequireBetaAccess
{
    public function handle($request, Closure $next)
    {
        // Works with strings or enums
        if (! Toggl::for($request->user())->active('beta-access')) {
            abort(403, 'Beta access required');
        }

        return $next($request);
    }
}
```

Using an enum for type safety:

```php
namespace App\Http\Middleware;

use App\Enums\FeatureFlag;
use Cline\Toggl\Toggl;
use Closure;

class RequireBetaAccess
{
    public function handle($request, Closure $next)
    {
        if (! Toggl::for($request->user())->active(FeatureFlag::BetaAccess)) {
            abort(403, 'Beta access required');
        }

        return $next($request);
    }
}
```

You can then register this middleware in your routes:

```php
Route::middleware([RequireBetaAccess::class])->group(function () {
    Route::get('/beta/dashboard', [BetaController::class, 'dashboard']);
});
```

## Custom Drivers

### Create Custom Driver

```php
namespace App\Drivers;

use Cline\Toggl\Contracts\Driver;

class RedisDriver implements Driver
{
    public function __construct(
        protected \Illuminate\Redis\RedisManager $redis
    ) {}

    public function get(string $feature, mixed $context): mixed
    {
        $key = "features:{$feature}:{$this->serializeContext($context)}";
        return $this->redis->get($key);
    }

    public function set(string $feature, mixed $context, mixed $value): void
    {
        $key = "features:{$feature}:{$this->serializeContext($context)}";
        $this->redis->set($key, $value);
    }

    // Implement other Driver methods...
}
```

### Register Custom Driver

```php
// In AppServiceProvider
use Cline\Toggl\Toggl;
use App\Drivers\RedisDriver;

public function boot(): void
{
    Toggl::extend('redis', function ($app, $config) {
        return new RedisDriver($app->make('redis'));
    });
}
```

### Use Custom Driver

```php
// config/toggl.php
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
    ],
],
```

## Caching Strategies

### Eager Loading

```php
// Load all features at once
$user = User::with('features')->find(1);

// Access without additional queries
Toggl::for($user)->active('feature-1');
Toggl::for($user)->active('feature-2');
```

### Cache Warming

```php
// Warm cache for common features
foreach ($users as $user) {
    Toggl::for($user)->load([
        'premium-access',
        'beta-features',
        'advanced-analytics',
    ]);
}

// With enums for type safety
foreach ($users as $user) {
    Toggl::for($user)->load([
        FeatureFlag::PremiumAccess,
        FeatureFlag::BetaFeatures,
        FeatureFlag::AdvancedAnalytics,
    ]);
}
```

### Manual Cache Control

```php
// Flush all cached feature states
Toggl::flushCache();

// Forget specific feature
Toggl::forget('feature-name');
```

## Testing

### Pest Helpers

```php
use Cline\Toggl\Toggl;

test('premium features require subscription', function () {
    $user = User::factory()->create(['subscription' => 'basic']);

    Toggl::define('premium-support', fn($u) => $u->subscription === 'premium');

    expect(Toggl::for($user)->active('premium-support'))->toBeFalse();

    $user->subscription = 'premium';
    $user->save();

    Toggl::flushCache(); // Clear cached results

    expect(Toggl::for($user)->active('premium-support'))->toBeTrue();
});
```

### Activate Features in Tests

```php
beforeEach(function () {
    Toggl::activateForEveryone([
        'testing-mode',
        'debug-toolbar',
    ]);
});

test('feature is active in tests', function () {
    expect(Toggl::active('testing-mode'))->toBeTrue();
});

// With enums
beforeEach(function () {
    Toggl::activateForEveryone([
        FeatureFlag::TestingMode,
        FeatureFlag::DebugToolbar,
    ]);
});

test('feature is active in tests', function () {
    expect(Toggl::active(FeatureFlag::TestingMode))->toBeTrue();
});
```

### Fake Features

```php
test('can fake features', function () {
    Toggl::define('feature-1', false);
    Toggl::define('feature-2', true);

    // Override for test
    Toggl::activate('feature-1');

    expect(Toggl::active('feature-1'))->toBeTrue();
});
```

## Scheduled Tasks

### Monitor Feature Usage

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $features = Toggl::stored();

        foreach ($features as $feature) {
            Metrics::gauge('feature.usage', 1, [
                'feature' => $feature,
                'active' => Toggl::active($feature) ? 'true' : 'false',
            ]);
        }
    })->everyFiveMinutes();
}
```

### Warn About Expiring Features

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        $expiring = Toggl::expiringSoon(days: 7);

        if (count($expiring) > 0) {
            Notification::route('slack', config('slack.webhook'))
                ->notify(new FeatureExpiringNotification($expiring));
        }
    })->daily();
}
```

## Performance Tips

1. **Use array driver for ephemeral features**
   ```php
   // Fast, in-memory, no persistence
   'default' => 'array',
   ```

2. **Batch load features**
   ```php
   // ✅ Good - one query
   Toggl::for($user)->load(['f1', 'f2', 'f3']);

   // ❌ Avoid - multiple queries
   Toggl::for($user)->active('f1');
   Toggl::for($user)->active('f2');
   Toggl::for($user)->active('f3');
   ```

3. **Cache resolver results**
   ```php
   Toggl::define('expensive-check', function ($user) use ($cache) {
       return $cache->remember(
           "feature-check-{$user->id}",
           3600,
           fn() => $this->expensiveCalculation($user)
       );
   });
   ```

4. **Use percentage strategy over database**
   ```php
   // ✅ Fast - no DB lookup
   Toggl::define('rollout')
       ->strategy(new PercentageStrategy(25));

   // ❌ Slower - DB lookup per check
   Toggl::define('rollout', fn($u) =>
       DB::table('rollouts')->where('user_id', $u->id)->exists()
   );
   ```

## Best Practices

1. **Centralize feature definitions**
   ```php
   // app/Providers/FeatureServiceProvider.php
   public function boot(): void
   {
       $this->defineAllFeatures();
   }

   private function defineAllFeatures(): void
   {
       // All features in one place
       Toggl::define('feature-1', ...);
       Toggl::define('feature-2', ...);
   }
   ```

2. **Document feature purpose**
   ```php
   // Purpose: Enable new checkout flow for Q1 2025 launch
   // Owner: Team Ecommerce
   // Rollout: 10% → 50% → 100% over 2 weeks
   Toggl::define('new-checkout')
       ->strategy(new PercentageStrategy(10))
       ->expiresAfter(weeks: 2);
   ```

3. **Clean up old features**
   ```php
   // Schedule regular audits
   protected function schedule(Schedule $schedule): void
   {
       $schedule->command('feature:audit')->monthly();
   }
   ```

4. **Monitor feature flags**
   ```php
   Event::listen(UnknownFeatureResolved::class, function ($event) {
       // Alert if production code references undefined feature
       if (app()->environment('production')) {
           Sentry::captureMessage("Unknown feature: {$event->feature}");
       }
   });
   ```

## Next Steps

- [Getting Started](getting-started.md) - Installation and setup
- [Basic Usage](basic-usage.md) - Core operations
- [Strategies](strategies.md) - Resolution strategies


Toggl provides middleware to protect routes based on feature activation status. Use these to ensure certain features are active (or inactive) before allowing access to routes.

## Available Middleware

### EnsureFeaturesAreActive

Aborts with 400 if any required features are inactive.

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive;

// Using static constructor
Route::get('/dashboard', DashboardController::class)
    ->middleware(EnsureFeaturesAreActive::using('new-dashboard'));

// Multiple features (all must be active)
Route::get('/analytics', AnalyticsController::class)
    ->middleware(EnsureFeaturesAreActive::using('analytics', 'reporting'));

// Middleware alias (register in bootstrap/app.php or Kernel)
Route::get('/dashboard', DashboardController::class)
    ->middleware('feature:new-dashboard,analytics');
```

### EnsureFeaturesAreInactive

Aborts with 400 if any specified features are active. Useful for legacy routes that should only be accessible when new features are disabled.

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive;

// Legacy endpoint only available when new dashboard is off
Route::get('/old-dashboard', LegacyDashboardController::class)
    ->middleware(EnsureFeaturesAreInactive::using('new-dashboard'));

// Multiple features (all must be inactive)
Route::get('/legacy-api', LegacyApiController::class)
    ->middleware(EnsureFeaturesAreInactive::using('api-v2', 'api-v3'));
```

## Registering Middleware Aliases

In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'feature' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive::class,
        'feature.inactive' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive::class,
    ]);
})
```

Or in `app/Http/Kernel.php` (Laravel 10):

```php
protected $middlewareAliases = [
    // ...
    'feature' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive::class,
    'feature.inactive' => \Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive::class,
];
```

## Custom Response Handling

By default, middleware aborts with a 400 status. Customize this behavior:

### For Active Checks

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreActive;

// In a service provider boot method
EnsureFeaturesAreActive::whenInactive(function ($request, $features) {
    // Redirect to upgrade page
    return redirect('/upgrade')->with('required_features', $features);
});

// Or return a custom response
EnsureFeaturesAreActive::whenInactive(function ($request, $features) {
    return response()->json([
        'error' => 'Feature not available',
        'required_features' => $features,
    ], 403);
});

// Reset to default behavior
EnsureFeaturesAreActive::whenInactive(null);
```

### For Inactive Checks

```php
use Cline\Cline\Toggl\Http\Middleware\EnsureFeaturesAreInactive;

// Redirect when trying to access legacy route with new features enabled
EnsureFeaturesAreInactive::whenActive(function ($request, $features) {
    return redirect('/dashboard')->with('message', 'You have been upgraded!');
});

// Reset to default behavior
EnsureFeaturesAreInactive::whenActive(null);
```

## Route Group Examples

### Feature-Gated Section

```php
// All routes require premium feature
Route::middleware(EnsureFeaturesAreActive::using('premium'))->group(function () {
    Route::get('/premium/dashboard', PremiumDashboardController::class);
    Route::get('/premium/analytics', PremiumAnalyticsController::class);
    Route::get('/premium/reports', PremiumReportsController::class);
});
```

### Beta Features

```php
// Beta routes only accessible when beta is enabled
Route::prefix('beta')
    ->middleware(EnsureFeaturesAreActive::using('beta-program'))
    ->group(function () {
        Route::get('/new-editor', BetaEditorController::class);
        Route::get('/ai-assistant', BetaAiController::class);
    });
```

### Legacy Routes During Migration

```php
// Old routes only available when new features are off
Route::middleware(EnsureFeaturesAreInactive::using('checkout-v2'))->group(function () {
    Route::get('/checkout', LegacyCheckoutController::class);
    Route::post('/checkout/process', LegacyCheckoutProcessController::class);
});

// New routes require new features
Route::middleware(EnsureFeaturesAreActive::using('checkout-v2'))->group(function () {
    Route::get('/checkout', NewCheckoutController::class);
    Route::post('/checkout/process', NewCheckoutProcessController::class);
});
```

### API Versioning

```php
// API v1 - legacy (only when v2 is not enabled)
Route::prefix('api/v1')
    ->middleware(EnsureFeaturesAreInactive::using('api-v2'))
    ->group(function () {
        Route::apiResource('users', Api\V1\UserController::class);
    });

// API v2 - new version
Route::prefix('api/v2')
    ->middleware(EnsureFeaturesAreActive::using('api-v2'))
    ->group(function () {
        Route::apiResource('users', Api\V2\UserController::class);
    });
```

## Debug Mode

In debug mode (`APP_DEBUG=true`), error messages include the feature names:

```
Required features [analytics, reporting] are not active.
Features [legacy-api] must be inactive.
```

In production, generic error messages are shown for security.

## Testing Routes with Middleware

```php
use Cline\Toggl\Toggl;
use App\Models\User;

test('premium route requires premium feature', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->deactivate('premium');

    // Act & Assert
    $this->actingAs($user)
        ->get('/premium/dashboard')
        ->assertStatus(400);
});

test('premium route accessible with feature active', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->activate('premium');

    // Act & Assert
    $this->actingAs($user)
        ->get('/premium/dashboard')
        ->assertOk();
});

test('legacy route inaccessible when new feature is active', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->activate('new-dashboard');

    // Act & Assert
    $this->actingAs($user)
        ->get('/old-dashboard')
        ->assertStatus(400);
});

test('legacy route accessible when new feature is inactive', function () {
    // Arrange
    $user = User::factory()->create();
    Toggl::for($user)->deactivate('new-dashboard');

    // Act & Assert
    $this->actingAs($user)
        ->get('/old-dashboard')
        ->assertOk();
});

test('different users can have different feature states', function () {
    // Arrange
    $premiumUser = User::factory()->create();
    $freeUser = User::factory()->create();

    Toggl::for($premiumUser)->activate('premium');
    Toggl::for($freeUser)->deactivate('premium');

    // Act & Assert
    $this->actingAs($premiumUser)
        ->get('/premium/dashboard')
        ->assertOk();

    $this->actingAs($freeUser)
        ->get('/premium/dashboard')
        ->assertStatus(400);
});
```

## Guest Context

For unauthenticated requests, the middleware uses a guest context (`TogglContext::simple('guest', 'guest')`). You can activate features globally for guests:

```php
// Activate globally for everyone including guests
Toggl::activateForEveryone('public-feature');

// Or define with a default value
Toggl::define('public-feature', true);
```


## Overview

Toggl supports global context management similar to the Warden package, allowing you to set an additional context layer for feature evaluation. This is particularly useful for multi-tenancy scenarios where features should behave differently based on the current organizational context (team, account, workspace, etc.).

## Global Context vs Entity Context

Understanding the difference between global context and entity context is crucial:

- **Entity Context**: The entity being checked (e.g., a specific user, model instance)
- **Global Context**: The global contextual environment (e.g., which team, account, or workspace is active)

## Basic Usage

### Setting Global Context

```php
use Cline\Toggl\Toggl;

// Set context to a team ID
Toggl::context()->to('team-123');

// Set context to an organization
Toggl::context()->to($organization);

// Clear context
Toggl::context()->clear();
```

### Checking Current Context

```php
// Check if context is set
if (Toggl::context()->hasContext()) {
    $context = Toggl::context()->current();
}
```

### Using Context in Feature Resolvers

Feature resolvers receive both the entity context and global context parameters:

```php
Toggl::define('premium-api', function ($entityContext, $globalContext = null) {
    // $entityContext = the user being checked
    // $globalContext = the current team/organization

    return $entityContext->team_id === $globalContext;
});

// Set team context
Toggl::context()->to(5);

// Check if user can access premium API in their team context
if (Toggl::for($user)->active('premium-api')) {
    // User can access premium API within team 5
}
```

## Multi-Tenancy Scenarios

### Team-Based Features

```php
// Define a feature that's only active for users within a specific team
Toggl::define('advanced-analytics', function ($user, $globalContext = null) {
    // Feature is active if user belongs to the current team context
    // and the team has the premium plan
    return $user->team_id === $globalContext
        && Team::find($globalContext)?->hasPremiumPlan();
});

// In your middleware or controller
Toggl::context()->to($currentTeam->id);

// Check feature for user
if (Toggl::active('advanced-analytics')) {
    // Show advanced analytics
}
```

### Account-Based Features

```php
Toggl::define('white-label', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false; // Not active without account context
    }

    return Account::find($globalContext)?->hasWhiteLabel() ?? false;
});

// Set account context
Toggl::context()->to($request->account()->id);

// Feature will be evaluated within account context
if (Toggl::active('white-label')) {
    // Apply white-label branding
}
```

### Workspace-Based Features

```php
Toggl::define('collaboration-tools', function ($user, $globalContext = null) {
    // User must be in a workspace and workspace must have collaboration enabled
    return $globalContext !== null
        && $user->workspaces->contains($globalContext)
        && Workspace::find($globalContext)?->hasCollaboration();
});

// Set workspace context in middleware
public function handle($request, Closure $next)
{
    if ($workspace = $request->route('workspace')) {
        Toggl::context()->to($workspace);
    }

    return $next($request);
}
```

## Context with Different Entity Types

### Object Entities with Properties

```php
// Use objects for entities with multiple properties
$user = (object) ['id' => 1, 'team_id' => 5];

Toggl::define('team-feature', function ($entityContext, $globalContext = null) {
    // $entityContext is an object with team information
    return isset($entityContext->team_id) && $entityContext->team_id === $globalContext;
});

Toggl::context()->to(5);

$isActive = Toggl::for($user)->active('team-feature'); // true
```

**Note**: Associative arrays are treated as multiple entities by the `for()` method, not as a single entity with properties. Use objects or implement `TogglContextable` for complex entity types.

### Model Entities

```php
Toggl::define('org-admin', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    return $user->isAdminOf($globalContext);
});

// Using Eloquent models
Toggl::context()->to($organization);

if (Toggl::for($user)->active('org-admin')) {
    // User is admin in this organization context
}
```

### Context as Objects

```php
// You can use objects as context to pass structured data
$teamContext = (object) ['id' => 99, 'tier' => 'enterprise'];

Toggl::define('enterprise-features', function ($user, $context = null) {
    return is_object($globalContext)
        && isset($globalContext->tier)
        && $globalContext->tier === 'enterprise';
});

Toggl::context()->to($teamContext);

if (Toggl::active('enterprise-features')) {
    // Show enterprise features
}
```

## Cache Behavior

Context changes automatically flush the feature cache to ensure fresh evaluation:

```php
Toggl::context()->to('team-123');
$result1 = Toggl::active('premium-api'); // Evaluated fresh

Toggl::context()->to('team-456');
// Cache is automatically flushed when context changes
$result2 = Toggl::active('premium-api'); // Re-evaluated with new context

Toggl::context()->clear();
// Cache is flushed when context is cleared
$result3 = Toggl::active('premium-api'); // Evaluated without context
```

## Integration with Strategies

Context works seamlessly with all built-in strategies:

### Conditional Strategy with Context

```php
use Cline\Toggl\Strategies\ConditionalStrategy;

Toggl::define('team-export', new ConditionalStrategy(
    fn ($user, $team = null) => $team !== null && $user->team_id === $globalContext,
    true,  // Value when condition is true
    false  // Value when condition is false
));
```

### Custom Strategies with Context

```php
use Cline\Toggl\Contracts\Strategy;

class TeamBasedStrategy implements Strategy
{
    public function __construct(
        private int $requiredTeamTier,
    ) {}

    public function resolve(mixed $entityContext, mixed $globalContext = null): mixed
    {
        if ($globalContext === null) {
            return false;
        }

        $team = Team::find($globalContext);

        return $team && $team->tier >= $this->requiredTeamTier;
    }
}

Toggl::define('advanced-features', new TeamBasedStrategy(requiredTeamTier: 2));
```

## Real-World Example: SaaS Application

```php
// Middleware to set account context
class SetAccountContext
{
    public function handle($request, Closure $next)
    {
        if ($account = $request->user()?->currentAccount()) {
            Toggl::context()->to($account->id);
        }

        return $next($request);
    }
}

// Feature definitions
Toggl::define('api-access', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    $account = Account::find($globalContext);

    return $account && $account->plan->hasApiAccess();
});

Toggl::define('team-collaboration', function ($user, $globalContext = null) {
    if ($globalContext === null) {
        return false;
    }

    $account = Account::find($globalContext);

    return $account
        && $account->plan->hasCollaboration()
        && $user->isTeamMember($account);
});

// In your controller
public function index()
{
    // Context is already set by middleware

    if (Toggl::active('api-access')) {
        // Show API documentation link
    }

    if (Toggl::active('team-collaboration')) {
        // Show team features
    }
}
```

## Testing with Context

```php
use Cline\Toggl\Toggl;

test('users can access team features within their team context', function () {
    $user = User::factory()->create(['team_id' => 5]);

    Toggl::define('team-dashboard', fn ($u, $globalContext = null) =>
        $u->team_id === $globalContext
    );

    // Set team context
    Toggl::context()->to(5);

    expect(Toggl::for($user)->active('team-dashboard'))->toBeTrue();

    // Change context to different team
    Toggl::context()->to(10);

    expect(Toggl::for($user)->active('team-dashboard'))->toBeFalse();

    // Clear context
    Toggl::context()->clear();

    expect(Toggl::for($user)->active('team-dashboard'))->toBeFalse();
});
```

## Best Practices

1. **Always set context in middleware** for consistent context across requests
2. **Check for null context** in your resolvers if the feature requires context
3. **Use model IDs for context** rather than full models for better serialization
4. **Clear context** in tests to avoid test pollution
5. **Document context requirements** in your feature definitions

## Migration from Direct Entity Checking

If you're currently checking features like this:

```php
// Before: mixing entity and global context
Toggl::for(['user' => $user, 'team' => $team])->active('feature');
```

Migrate to context-based approach:

```php
// After: separate entity and global context
Toggl::context()->to($team->id);
Toggl::for($user)->active('feature');
```

This provides better separation of concerns and more consistent behavior across your application.


Activate features at organizational levels (company, division, org, team) that automatically apply to all matching contexts without duplicating database records.

## Setup

### 1. Implement TogglContextable Interface

```php
use Cline\Toggl\Contracts\TogglContextable;
use Cline\Toggl\Concerns\HasTogglContext;
use Cline\Toggl\Support\TogglContext;
use Cline\Toggl\Support\FeatureScope;

class User extends Model implements TogglContextable
{
    use HasTogglContext;

    public function toTogglContext(): TogglContext
    {
        return new TogglContext(
            id: $this->getKey(),
            type: static::class,
            scope: new FeatureScope(
                kind: 'user',
                scopes: [
                    'company_id' => $this->company_id,
                    'division_id' => $this->division_id,
                    'org_id' => $this->org_id,
                    'team_id' => $this->team_id,
                    'user_id' => $this->id,
                ],
            ),
        );
    }
}
```

### 2. Add Scope Columns to Your Model

```php
// Migration
$table->integer('company_id')->nullable();
$table->integer('division_id')->nullable();
$table->integer('org_id')->nullable();
$table->integer('team_id')->nullable();
```

---

## Basic Usage

### Enable Scope Resolution with withScopes()

```php
$user = User::find(1);

// Check feature with scope resolution enabled
Toggl::for($user)->withScopes()->active('premium-dashboard');
```

### Activate for All Users in an Organization

Use the fluent `withScopes()` method on conductors:

```php
// Activate at org level (applies to all users in org 2)
Toggl::activate('premium-dashboard')
    ->withScopes([
        'company_id' => 3,
        'org_id' => 2,
        'user_id' => null,  // Wildcard: any user
    ])
    ->for($user);

// All users in org 2 now have access
$user = User::find(1); // company_id=3, org_id=2
Toggl::for($user)->withScopes()->active('premium-dashboard'); // true
```

### Wildcard Matching (null Values)

```php
// Activate for all users in ANY team within org 2
Toggl::activate('shared-analytics')
    ->withScopes([
        'company_id' => 3,
        'org_id' => 2,
        'team_id' => null,  // Matches any team
        'user_id' => null,
    ])
    ->for($user);

// Both users have access despite different teams
$userTeamA = User::find(1); // team_id=10
$userTeamB = User::find(2); // team_id=20
Toggl::for($userTeamA)->withScopes()->active('shared-analytics'); // true
Toggl::for($userTeamB)->withScopes()->active('shared-analytics'); // true
```

---

## Features with Values

### Set Configuration at Organizational Level

```php
Toggl::activate('theme')
    ->withValue('corporate-blue')
    ->withScopes([
        'company_id' => 5,
        'user_id' => null,
    ])
    ->for($user);

// Users inherit the company theme
Toggl::for($user)->withScopes()->value('theme'); // 'corporate-blue'
```

---

## Precedence: Exact Context Wins

User-specific activations override scoped ones:

```php
// Activate at org level
Toggl::activate('theme')
    ->withValue('org-theme')
    ->withScopes([
        'org_id' => 2,
        'user_id' => null,
    ])
    ->for($user);

// Override for specific user
Toggl::for($user)->activate('theme', 'user-theme');

// User's specific value takes precedence
Toggl::for($user)->withScopes()->value('theme'); // 'user-theme'
```

---

## Explicit Scope Scope

You can provide an explicit scope instead of extracting from context:

```php
$user = User::find(1);

// Use explicit scope instead of extracting from context
Toggl::for($user)->withScopes([
    'company_id' => 3,
    'org_id' => 5,
], 'user')->active('premium-dashboard');
```

---

## Deactivation

```php
Toggl::deactivate('test-feature')
    ->withScopes([
        'company_id' => 3,
        'user_id' => null,
    ])
    ->for($user);
```

---

## Real-World Scenarios

### SaaS Multi-Tenant Scope

```php
// Premium feature for entire organization
Toggl::activate('advanced-reporting')
    ->withScopes([
        'company_id' => 10,
        'division_id' => 20,
        'org_id' => 30,
        'user_id' => null,
    ])
    ->for($user);

// Feature enabled for entire division (multiple orgs)
Toggl::activate('division-wide-feature')
    ->withScopes([
        'division_id' => 1,
        'org_id' => null,  // Any org in division
        'user_id' => null,
    ])
    ->for($user);
```

### Feature Rollout by Tier

```php
// Enable premium for specific organizations
Toggl::activate('premium-analytics')
    ->withScopes([
        'company_id' => 5,
        'org_id' => 100,
        'user_id' => null,
    ])
    ->for($user);

// Standard orgs don't have it
$premiumUser = User::where('org_id', 100)->first();
$standardUser = User::where('org_id', 200)->first();

Toggl::for($premiumUser)->withScopes()->active('premium-analytics');  // true
Toggl::for($standardUser)->withScopes()->active('premium-analytics'); // false
```

---

## Key Points

| Feature | Description |
|---------|-------------|
| **No Duplication** | One database record activates feature for all matching contexts |
| **Wildcards** | `null` values match any value at that scope level |
| **Exact Wins** | User-specific activations override scoped ones |
| **Kind Matching** | The `kind` parameter must match between activation and context |
| **Explicit withScopes()** | Must call `withScopes()` to enable scoped resolution |


Toggl creates feature snapshots automatically when features are activated or deactivated, providing point-in-time recovery and audit trails. Over time, these snapshots can accumulate. This guide covers how to configure and automate snapshot pruning.

## Configuration

Configure snapshot functionality and retention in `config/toggl.php`:

```php
'snapshots' => [
    'enabled' => env('TOGGL_SNAPSHOTS_ENABLED', true),
    'driver' => env('TOGGL_SNAPSHOT_DRIVER') ? SnapshotDriver::tryFrom(env('TOGGL_SNAPSHOT_DRIVER')) : null,
    'pruning' => [
        'retention_days' => env('TOGGL_SNAPSHOT_RETENTION_DAYS', 365),
    ],
],
```

- Set `enabled` to `false` to completely disable snapshot functionality
- The default retention period is 365 days. Set to `0` to disable pruning while keeping snapshots enabled

## Manual Pruning

Run the prune command manually:

```bash
# Use configured retention period (default: 365 days)
php artisan toggl:prune-snapshots

# Override retention period to 30 days
php artisan toggl:prune-snapshots --days=30
```

## Scheduled Pruning

Add the command to your scheduler for automatic cleanup. In `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    // Run daily at 3 AM
    $schedule->command('toggl:prune-snapshots')->dailyAt('03:00');

    // Or run weekly on Sundays
    $schedule->command('toggl:prune-snapshots')->weeklyOn(0, '03:00');
}
```

### Laravel 11+ Scheduler

In `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('toggl:prune-snapshots')->dailyAt('03:00');
```

## Environment-Specific Configuration

Set different retention periods or disable snapshots per environment:

```env
# .env.production - snapshots enabled with 1 year retention
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=365

# .env.staging - snapshots enabled with 30 day retention
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=30

# .env.local - disable snapshots entirely
TOGGL_SNAPSHOTS_ENABLED=false

# Or keep snapshots enabled but disable pruning
TOGGL_SNAPSHOTS_ENABLED=true
TOGGL_SNAPSHOT_RETENTION_DAYS=0
```

## What Gets Deleted

When a snapshot is pruned, the following related data is also deleted:

- **Snapshot entries** - Individual feature states captured in the snapshot
- **Snapshot events** - Audit trail of snapshot operations (create, restore, delete)

This is handled automatically via Eloquent's `deleting` event on the `FeatureSnapshot` model.

## Compliance Considerations

Before configuring pruning, consider:

1. **Regulatory requirements** - Some industries require audit trails for specific periods
2. **Internal policies** - Your organization may have data retention policies
3. **Debugging needs** - Older snapshots can help diagnose production issues

Recommended retention periods by use case:

| Use Case | Retention Period |
|----------|------------------|
| Development | 7-30 days |
| Staging | 30-90 days |
| Production (standard) | 365 days |
| Production (regulated) | As required by compliance |

## Monitoring Pruning

Track pruning operations in your logs:

```php
// In a custom event listener
use Illuminate\Console\Events\CommandFinished;

Event::listen(CommandFinished::class, function ($event) {
    if ($event->command === 'toggl:prune-snapshots') {
        Log::info('Snapshot pruning completed', [
            'exit_code' => $event->exitCode,
        ]);
    }
});
```

## Performance Considerations

The prune command uses chunked queries to handle large numbers of snapshots efficiently:

- Snapshots are processed in batches of 100
- Each snapshot's related entries and events are deleted via Eloquent events
- For very large datasets, consider running during off-peak hours

## See Also

- [Advanced Usage](advanced-usage.md) - Events and middleware
- [Getting Started](getting-started.md) - Initial setup
