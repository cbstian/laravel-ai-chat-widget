<?php

declare(strict_types=1);

use Livewire\Livewire;
use Cbstian\AiChat\Livewire\AiChatWidget;
use Cbstian\AiChat\Models\AiChatTurnLog;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\User;

it('renders the floating widget for an authenticated user', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'widget@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test(AiChatWidget::class)
        ->assertOk()
        ->assertSee('Asistente IA', false);
});

it('sends a message through the widget using the fake gateway', function () {
    FakeChatAgent::fake(['Hola desde el widget.']);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'widget-send@example.test',
    ]);

    $this->actingAs($user);

    // submit() encola ask() vía $this->js(); en tests hay que invocar ask() a mano.
    // Livewire stream() escribe a stdout; lo bufferizamos para no marcar risky.
    ob_start();

    try {
        Livewire::test(AiChatWidget::class)
            ->set('open', true)
            ->set('prompt', 'Hola widget')
            ->call('submit')
            ->assertSet('pendingQuestion', 'Hola widget')
            ->call('ask')
            ->assertSet('error', null)
            ->assertSet('pendingQuestion', '');
    } finally {
        ob_end_clean();
    }

    FakeChatAgent::assertPrompted('Hola widget');
    FakeChatAgent::assertPromptedTimes(1);

    expect(AiChatTurnLog::query()->count())->toBe(1);
    expect(AiChatTurnLog::query()->first()->succeeded)->toBeTrue();
});
