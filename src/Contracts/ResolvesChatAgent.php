<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesChatAgent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function make(?Authenticatable $user, array $context = []): object;
}
