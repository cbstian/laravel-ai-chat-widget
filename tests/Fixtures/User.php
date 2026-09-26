<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $table = 'users';
}
