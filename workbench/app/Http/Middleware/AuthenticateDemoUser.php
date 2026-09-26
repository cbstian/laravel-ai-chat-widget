<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

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
            return 'Este es el agente local de Workbench. El widget, el streaming y la persistencia funcionan sin un proveedor real.';
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
