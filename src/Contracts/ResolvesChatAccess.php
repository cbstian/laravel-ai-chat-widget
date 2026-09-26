<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesChatAccess
{
    public function canAccess(?Authenticatable $user): bool;
}
