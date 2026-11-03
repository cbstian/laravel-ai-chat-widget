<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WriteTool extends Tool
{
    protected string $name = 'write-tool';

    protected string $description = 'Modificaría datos. M1 no la expone.';

    public function handle(Request $request): Response
    {
        return Response::text('written');
    }
}
