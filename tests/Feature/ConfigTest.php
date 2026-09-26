<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Drivers\AgentChatDriver;
use Illuminate\Support\Facades\File;

it('merges the ai-chat config', function () {
    expect(config('ai-chat.title'))->toBe('Asistente IA');
    expect(config('ai-chat.colors.primary'))->toBe('#4A4D46');
    expect(config('ai-chat.documents.paths'))->toBeArray()
        ->and(config('ai-chat.documents.max_bytes'))->toBe(200_000)
        ->and(config('ai-chat.tool_labels.ReadMarkdownDocument'))->toBe('Leyendo documentación…');
});

it('ships the integration guide an agent can follow', function () {
    $root = dirname(__DIR__, 2);

    expect(is_file($root.'/docs/INTEGRATION.md'))->toBeTrue()
        ->and(is_file($root.'/resources/boost/guidelines/core.blade.php'))->toBeTrue()
        ->and(is_file($root.'/resources/boost/skills/ai-chat-widget/SKILL.md'))->toBeTrue();

    $guide = file_get_contents($root.'/docs/INTEGRATION.md');

    expect($guide)->toContain('AI_CHAT_AGENT')
        ->and($guide)->toContain('ListMarkdownDocuments')
        ->and($guide)->toContain('ReadMarkdownDocument')
        ->and($guide)->toContain('@aiChatStyles')
        ->and($guide)->toContain('AI_CHAT_FILAMENT_HOOK');
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
            ->expectsOutputToContain('AI_CHAT_AGENT=')
            ->expectsOutputToContain('docs/INTEGRATION.md')
            ->assertSuccessful();

        expect(collect(File::files($migrations))->contains(
            fn ($file): bool => str_contains($file->getFilename(), 'create_ai_chat_sessions_table'),
        ))->toBeTrue();

        $agent = app_path('Ai/ChatAgent.php');
        $factory = app_path('Ai/ChatAgentFactory.php');

        expect($agent)->toBeReadableFile()
            ->and(File::get($agent))->toContain('namespace App\\Ai;')
            ->and(File::get($agent))->toContain('new ListMarkdownDocuments')
            ->and(File::get($factory))->toContain('implements ResolvesChatAgent')
            ->and(app_path('Ai/ChatAccessGate.php'))->toBeReadableFile();

        File::put($agent, File::get($agent)."\n// local\n");

        $this->artisan('ai-chat:install')->assertSuccessful();

        expect(File::get($agent))->toContain('// local');
    } finally {
        foreach (File::files($migrations) as $file) {
            if (str_contains($file->getFilename(), 'ai_chat_')) {
                File::delete($file->getPathname());
            }
        }

        File::delete([$config, $welcome]);
        File::deleteDirectory($assets);
        File::deleteDirectory(app_path('Ai'));
    }
});
