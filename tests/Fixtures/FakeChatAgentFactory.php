<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Illuminate\Contracts\Auth\Authenticatable;

class FakeChatAgentFactory implements ResolvesChatAgent
{
    public function make(?Authenticatable $user, array $context = []): object
    {
        return new FakeChatAgent;
    }
}
