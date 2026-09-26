<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Illuminate\Contracts\Auth\Authenticatable;

class DemoChatAgentFactory implements ResolvesChatAgent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function make(?Authenticatable $user, array $context = []): object
    {
        return new DemoChatAgent;
    }
}
