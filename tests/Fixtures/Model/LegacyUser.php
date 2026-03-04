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
use Override;

/**
 * Test fixture model with ULID primary key and legacy numeric id attribute.
 *
 * @property null|int    $id   Legacy numeric identifier
 * @property null|string $name
 * @property string      $ulid Primary ULID key
 * @author Brian Faust <brian@cline.sh>
 */
final class LegacyUser extends Model
{
    use HasFactory;

    #[Override()]
    public $timestamps = false;

    #[Override()]
    public $incrementing = false;

    #[Override()]
    protected $table = 'legacy_users';

    #[Override()]
    protected $primaryKey = 'ulid';

    #[Override()]
    protected $keyType = 'string';

    #[Override()]
    protected $fillable = ['ulid', 'id', 'name'];
}
