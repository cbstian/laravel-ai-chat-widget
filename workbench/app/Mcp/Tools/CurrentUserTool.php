<?php

declare(strict_types=1);

namespace Workbench\App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CurrentUserTool extends Tool
{
    protected string $name = 'current-user';

    protected string $description = 'Devuelve el nombre de la persona autenticada en este chat. No modifica datos.';

    public function handle(Request $request): Response
    {
        $name = data_get($request->user(), 'name');

        return Response::text(is_string($name) && $name !== '' ? $name : 'invitado');
    }
}
