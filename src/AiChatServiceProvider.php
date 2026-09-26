<?php

declare(strict_types=1);

namespace Cbstian\AiChat;

use Cbstian\AiChat\Console\Commands\InstallCommand;
use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Contracts\ResolvesChatAccess;
use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Cbstian\AiChat\Drivers\AgentChatDriver;
use Cbstian\AiChat\Livewire\AiChatWidget;
use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AiChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-chat.php', 'ai-chat');

        $this->app->singleton(ResolvesChatAgent::class, function (Application $app) {
            $class = config('ai-chat.agent');

            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                throw new RuntimeException(
                    'Configura ai-chat.agent con una clase que implemente ResolvesChatAgent.',
                );
            }

            return $app->make($class);
        });

        $this->app->singleton(ResolvesChatAccess::class, function (Application $app) {
            $class = config('ai-chat.access');

            if (! is_string($class) || $class === '' || ! class_exists($class)) {
                return null;
            }

            return $app->make($class);
        });

        $this->app->singleton(ChatDriver::class, function (Application $app) {
            $driver = config('ai-chat.driver');

            if (is_string($driver) && $driver !== '' && class_exists($driver)) {
                return $app->make($driver);
            }

            return new AgentChatDriver(
                $app->make(ResolvesChatAgent::class),
                $app->make(ResolvesChatAccess::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-chat');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (class_exists(Livewire::class)) {
            Livewire::component('ai-chat-widget', AiChatWidget::class);
        }

        Blade::directive('aiChatStyles', function () {
            $path = addslashes(__DIR__.'/../resources/css/ai-chat.css');

            return "<?php echo '<style>'.(is_file('{$path}') ? file_get_contents('{$path}') : '').'</style>'; ?>";
        });

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);

            $this->publishes([
                __DIR__.'/../config/ai-chat.php' => config_path('ai-chat.php'),
            ], ['ai-chat', 'ai-chat-config']);

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ai-chat'),
            ], ['ai-chat', 'ai-chat-views']);

            $this->publishes([
                __DIR__.'/../resources/css' => public_path('vendor/ai-chat'),
            ], ['ai-chat', 'ai-chat-assets']);

            $this->publishes([
                __DIR__.'/../resources/stubs/welcome.md' => resource_path('ai/chat/welcome.md'),
            ], ['ai-chat', 'ai-chat-stubs']);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], ['ai-chat', 'ai-chat-migrations']);
        }

        $this->registerFilamentHook();
    }

    protected function registerFilamentHook(): void
    {
        if (! config('ai-chat.filament.register_render_hook')) {
            return;
        }

        if (! class_exists(Filament::class)) {
            return;
        }

        if (! class_exists(PanelsRenderHook::class)) {
            return;
        }

        Filament::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Blade::render('@livewire(\'ai-chat-widget\')'),
        );
    }
}
