<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Exceptions;

use function gettype;
use function sprintf;

/**
 * Thrown when attempting to assign non-string value to ULID primary key.
 *
 * ULID primary keys require string values in the 26-character Crockford
 * base32 format (e.g., "01ARZ3NDEKTSV4RRFFQ69G5FAV"). Non-string types
 * cannot maintain the lexicographic sorting and time-ordering properties
 * that make ULIDs useful for distributed systems.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class NonStringUlidPrimaryKeyException extends InvalidPrimaryKeyValueException
{
    /**
     * Create exception for non-string value assigned to ULID primary key.
     *
     * @param  mixed $value The invalid value that was provided
     * @return self  Exception instance with descriptive error message
     */
    public static function forValue(mixed $value): self
    {
        return new self(
            sprintf(
                'Cannot assign non-string value to ULID primary key. Got: %s',
                gettype($value),
            ),
        );
    }
}
