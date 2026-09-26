<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

class DemoChatAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Agente local de Workbench. No llama a un proveedor real.';
    }
}
