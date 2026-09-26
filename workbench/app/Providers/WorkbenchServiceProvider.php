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
        $packageRoot = dirname(__DIR__, 3);

        foreach ([
            $packageRoot.'/vendor/laravel/ai/database/migrations',
            $packageRoot.'/database/migrations',
        ] as $path) {
            if (is_dir($path) && ! $this->migrationsAlreadyPublished($path)) {
                $this->loadMigrationsFrom($path);
            }
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

    protected function migrationsAlreadyPublished(string $path): bool
    {
        if (! str_contains($path, '/database/migrations') || str_contains($path, '/vendor/laravel/ai/')) {
            return false;
        }

        $published = database_path('migrations');

        if (! is_dir($published)) {
            return false;
        }

        foreach (scandir($published) ?: [] as $file) {
            if (str_contains($file, 'create_ai_chat_sessions_table')) {
                return true;
            }
        }

        return false;
    }
}
