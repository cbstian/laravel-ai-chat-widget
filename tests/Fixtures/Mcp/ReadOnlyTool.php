<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Tests\Fixtures\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ReadOnlyTool extends Tool
{
    protected string $name = 'read-only-demo';

    protected string $description = 'Devuelve el id de la persona autenticada. No modifica datos.';

    public function handle(Request $request): Response
    {
        $id = $request->user()?->getAuthIdentifier();

        return Response::text('user:'.(is_scalar($id) ? (string) $id : 'none'));
    }
}
