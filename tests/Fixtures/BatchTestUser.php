<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Test user model for batch evaluation tests.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class BatchTestUser extends Model
{
    use HasFactory;

    #[Override()]
    public $timestamps = false;

    #[Override()]
    protected $table = 'users';

    #[Override()]
    protected $guarded = [];
}
