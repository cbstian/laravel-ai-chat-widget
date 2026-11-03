<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Laravel\Mcp\Server;
use Workbench\App\Mcp\Tools\CurrentUserTool;

class DemoMcpServer extends Server
{
    /**
     * @var array<int, class-string<CurrentUserTool>>
     */
    protected array $tools = [
        CurrentUserTool::class,
    ];
}
