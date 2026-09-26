<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Cbstian\AiChat\Contracts\ResolvesChatAccess;
use Illuminate\Contracts\Auth\Authenticatable;

class AllowAllChatAccess implements ResolvesChatAccess
{
    public function canAccess(?Authenticatable $user): bool
    {
        return $user !== null;
    }
}
