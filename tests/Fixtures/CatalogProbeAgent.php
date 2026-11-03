<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures;

use Cbstian\AiChat\Mcp\McpToolCatalog;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CatalogProbeAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /** @var list<string> */
    public static array $names = [];

    public function instructions(): Stringable|string
    {
        return 'Agente de prueba del catálogo MCP.';
    }

    public function tools(): iterable
    {
        self::$names = [];

        foreach (app(McpToolCatalog::class)->tools() as $tool) {
            if (is_object($tool) && method_exists($tool, 'name')) {
                $name = $tool->name();

                if (is_string($name)) {
                    self::$names[] = $name;
                }
            }
        }

        return [];
    }
}
