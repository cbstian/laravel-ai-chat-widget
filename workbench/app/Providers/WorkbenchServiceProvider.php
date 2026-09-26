<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Ai\DemoChatAgentFactory;
use Workbench\App\Http\Middleware\AuthenticateDemoUser;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $aiMigrations = dirname(__DIR__, 3).'/vendor/laravel/ai/database/migrations';

        if (is_dir($aiMigrations)) {
            $this->loadMigrationsFrom($aiMigrations);
        }

        Route::pushMiddlewareToGroup('web', AuthenticateDemoUser::class);

        config([
            'auth.providers.users.model' => User::class,
            'cache.default' => 'array',
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'workbench',
            'ai.conversations.generate_title' => false,
            'ai-chat.enabled' => true,
            'ai-chat.persist' => true,
            'ai-chat.verbose_logs' => true,
            'ai-chat.agent' => DemoChatAgentFactory::class,
            'ai-chat.provider' => 'openai',
            'ai-chat.model' => 'gpt-4o-mini',
            'ai-chat.title' => 'Asistente IA',
            'ai-chat.subtitle' => 'Prueba local de Workbench',
        ]);
    }
}
