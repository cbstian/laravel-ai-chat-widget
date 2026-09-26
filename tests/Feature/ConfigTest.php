<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Drivers\AgentChatDriver;

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
    $this->artisan('ai-chat:install')
        ->expectsOutputToContain('Laravel AI Chat instalado.')
        ->assertSuccessful();
});
