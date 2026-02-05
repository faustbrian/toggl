<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Events;

use Cline\Toggl\Support\ContextResolver;
use Cline\Toggl\Support\TogglContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Event fired when an undefined feature is resolved.
 *
 * Dispatched when a feature flag is checked but hasn't been defined. This can happen
 * when checking features that don't exist, or when using the gate driver without a
 * matching gate definition. Useful for logging missing features, debugging typos in
 * feature names, or tracking attempted access to non-existent features.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @psalm-immutable
 */
final readonly class UnknownFeatureResolved
{
    /**
     * Create a new Unknown Feature Resolved event.
     *
     * @param string       $feature The name of the unknown feature that was accessed
     * @param TogglContext $context The context (user, team, etc.) in which the feature was accessed
     */
    public function __construct(
        public string $feature,
        public TogglContext $context,
    ) {}

    /**
     * Resolve the underlying model from the context when possible.
     *
     * @return null|Model The resolved model instance, or null if unavailable
     */
    public function toModel(): ?Model
    {
        return ContextResolver::resolveModel($this->context);
    }
}
