<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Drivers\AgentChatDriver;
use Illuminate\Support\Facades\File;

it('merges the ai-chat config', function () {
    expect(config('ai-chat.title'))->toBe('Asistente IA');
    expect(config('ai-chat.colors.primary'))->toBe('#4A4D46');
});

it('loads the package views', function () {
    expect(view()->exists('ai-chat::livewire.widget'))->toBeTrue();
});

it('binds ChatDriver to AgentChatDriver by default', function () {
    expect(app(ChatDriver::class))->toBeInstanceOf(AgentChatDriver::class);
});

it('registers the install artisan command', function () {
    $migrations = database_path('migrations');
    $config = config_path('ai-chat.php');
    $welcome = resource_path('ai/chat/welcome.md');
    $assets = public_path('vendor/ai-chat');

    try {
        $this->artisan('ai-chat:install')
            ->expectsOutputToContain('Laravel AI Chat instalado.')
            ->assertSuccessful();

        expect(collect(File::files($migrations))->contains(
            fn ($file): bool => str_contains($file->getFilename(), 'create_ai_chat_sessions_table'),
        ))->toBeTrue();
    } finally {
        foreach (File::files($migrations) as $file) {
            if (str_contains($file->getFilename(), 'ai_chat_')) {
                File::delete($file->getPathname());
            }
        }

        File::delete([$config, $welcome]);
        File::deleteDirectory($assets);
    }
});
