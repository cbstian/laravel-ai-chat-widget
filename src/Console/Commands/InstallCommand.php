<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ai-chat:install {--force : Sobrescribe archivos publicados}';

    protected $description = 'Publica config, migraciones, assets y stub de welcome del chat IA';

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

        $this->newLine();
        $this->info('Laravel AI Chat instalado.');
        $this->line('1. Configura AI_CHAT_AGENT (y opcional AI_CHAT_ACCESS) en .env o config/ai-chat.php');
        $this->line('2. php artisan migrate');
        $this->line('3. Incluye @livewire(\'ai-chat-widget\') en Blade o habilita el hook de Filament');
        $this->line('4. Opcional: copia resources/ai/chat/welcome.md');

        return self::SUCCESS;
    }
}
