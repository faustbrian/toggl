<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use Facade\IgnitionContracts\BaseSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use Facade\IgnitionContracts\Solution;
use InvalidArgumentException;

/**
 * Thrown when strategy data does not match the expected type for a feature flag strategy.
 *
 * This exception is raised when feature flag activation strategies receive data
 * in an incompatible format. Different strategies require specific data types:
 * percentage strategies need integer values (0-100), while complex strategies
 * like variant or conditional rollouts require array configuration data.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidStrategyDataException extends InvalidArgumentException implements ProvidesSolution, TogglException
{
    public function getSolution(): Solution
    {
        /** @var BaseSolution $solution */
        $solution = BaseSolution::create('Review package usage and configuration.');

        return $solution
            ->setSolutionDescription('Exception: '.$this->getMessage())
            ->setDocumentationLinks([
                'Package documentation' => 'https://github.com/cline/toggl',
            ]);
    }
}
