<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class HiddenTool extends Tool
{
    protected string $name = 'hidden-tool';

    protected string $description = 'No debe registrarse.';

    public function shouldRegister(): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        return Response::text('hidden');
    }
}
