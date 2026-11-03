<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures\Mcp;

use Laravel\Mcp\Server;

class DemoServer extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        ReadOnlyTool::class,
        WriteTool::class,
    ];
}
