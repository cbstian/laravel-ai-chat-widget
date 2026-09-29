<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'ai-chat:install {--force : Sobrescribe archivos publicados}';

    protected $description = 'Publica config, migraciones, assets, welcome y clases de agente del chat IA';

    public function handle(): int
    {
        $params = ['--provider' => 'Cbstian\\AiChat\\AiChatServiceProvider'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params + ['--tag' => 'ai-chat-config']);
        $this->call('vendor:publish', $params + ['--tag' => 'ai-chat-migrations']);
        $this->call('vendor:publish', $params + ['--tag' => 'ai-chat-assets']);
        $this->call('vendor:publish', $params + ['--tag' => 'ai-chat-stubs']);

        $namespace = $this->publishAgentClasses((bool) $this->option('force'));

        $this->newLine();
        $this->info('Laravel AI Chat instalado.');
        $this->line('1. Publica laravel/ai y migra (historial del LLM, además de las tablas de este paquete):');
        $this->line('   php artisan vendor:publish --provider="Laravel\\Ai\\AiServiceProvider" --tag=ai-config');
        $this->line('   php artisan vendor:publish --provider="Laravel\\Ai\\AiServiceProvider"');
        $this->line('   php artisan migrate');
        $this->line('   Si la app ya migró laravel/ai 0.x, corre antes el backfill a steps/status de su guía de upgrade a 1.0.');
        $this->line('2. Añade en .env (este comando no modifica .env):');
        $this->line('   AI_CHAT_AGENT='.$namespace.'\\ChatAgentFactory');
        $this->line('   AI_CHAT_ACCESS='.$namespace.'\\ChatAccessGate');
        $this->line('   AI_CHAT_PROVIDER=openai');
        $this->line('   AI_CHAT_MODEL=gpt-4o-mini');
        $this->line('   OPENAI_API_KEY=');
        $this->line('3. Monta @aiChatStyles y @livewire(\'ai-chat-widget\'), o AI_CHAT_FILAMENT_HOOK=true');
        $this->line('4. Lee docs/INTEGRATION.md del paquete antes de conectar tools de dominio.');

        return self::SUCCESS;
    }

    protected function publishAgentClasses(bool $force): string
    {
        $namespace = rtrim($this->laravel->getNamespace(), '\\').'\\Ai';
        $directory = app_path('Ai');
        $stubDirectory = dirname(__DIR__, 3).'/resources/stubs/agent';

        foreach ([
            'ChatAgent.php.stub' => 'ChatAgent.php',
            'ChatAgentFactory.php.stub' => 'ChatAgentFactory.php',
            'ChatAccessGate.php.stub' => 'ChatAccessGate.php',
        ] as $stub => $filename) {
            $target = $directory.DIRECTORY_SEPARATOR.$filename;

            if (is_file($target) && ! $force) {
                $this->warn('Ya existe '.$target.'. Usa ai-chat:install --force para sobrescribirlo.');

                continue;
            }

            File::ensureDirectoryExists($directory);
            File::put($target, str_replace(
                '{{ namespace }}',
                $namespace,
                File::get($stubDirectory.'/'.$stub),
            ));
        }

        return $namespace;
    }
}
