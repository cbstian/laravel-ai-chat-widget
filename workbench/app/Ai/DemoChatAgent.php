<?php

declare(strict_types=1);

namespace Workbench\App\Ai;

use Cbstian\AiChat\Mcp\McpToolCatalog;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class DemoChatAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Agente local de Workbench. No llama a un proveedor real. Si hay tools MCP de solo lectura, puedes usarlas.';
    }

    public function tools(): iterable
    {
        return [
            ...app(McpToolCatalog::class)->tools(),
        ];
    }
}
