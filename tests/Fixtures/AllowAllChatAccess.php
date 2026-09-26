<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Cbstian\AiChat\Contracts\ResolvesChatAccess;

class AllowAllChatAccess implements ResolvesChatAccess
{
    public function canAccess(?Authenticatable $user): bool
    {
        return $user !== null;
    }
}
