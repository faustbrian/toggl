<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use InvalidArgumentException;

/**
 * Base exception for invalid primary key values.
 *
 * This abstract exception is raised when invalid value types are assigned to model
 * primary keys that require string types, such as UUIDs (Universally Unique Identifiers)
 * or ULIDs (Universally Unique Lexicographically Sortable Identifiers). These
 * identifier formats require string representation for proper storage and querying.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidPrimaryKeyValueException extends InvalidArgumentException implements TogglException
{
    // Abstract base class - no methods required
}
