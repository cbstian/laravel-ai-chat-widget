<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Cbstian\AiChat\Mcp\McpToolCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Ai\DemoChatAgent;
use Workbench\App\Models\User;

class AuthenticateDemoUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        DemoChatAgent::fake(function (string $prompt): string {
            $names = [];

            foreach (app(McpToolCatalog::class)->tools() as $tool) {
                if (is_object($tool) && method_exists($tool, 'name')) {
                    $name = $tool->name();

                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }
            }

            $list = $names === [] ? 'ninguna' : implode(', ', $names);

            return 'Este es el agente local de Workbench. Tools MCP de este turno: '.$list.'. Dijiste: '.$prompt;
        });

        if (! Auth::check()) {
            $user = User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => 'password'],
            );

            Auth::login($user);
        }

        return $next($request);
    }
}
