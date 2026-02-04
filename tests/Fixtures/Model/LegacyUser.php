<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture model with ULID primary key and legacy numeric id attribute.
 *
 * @property string $ulid Primary ULID key
 * @property null|int $id Legacy numeric identifier
 * @property null|string $name
 */
final class LegacyUser extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'legacy_users';

    protected $primaryKey = 'ulid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['ulid', 'id', 'name'];
}
