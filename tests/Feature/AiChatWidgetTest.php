<?php

declare(strict_types=1);

use Cbstian\AiChat\Livewire\AiChatWidget;
use Cbstian\AiChat\Models\AiChatTurnLog;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\User;
use Livewire\Livewire;

it('renders an empty root for a guest', function () {
    Livewire::test(AiChatWidget::class)
        ->assertOk()
        ->assertDontSee('Asistente IA', false);
});

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

it('ships markdown table styles that stay visible inside the panel', function () {
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/ai-chat.css');

    expect($css)
        ->toContain('.pc-ai-chat__table')
        ->toContain('overflow-x: auto')
        ->toContain('border-collapse: collapse')
        ->toContain('display: table-cell');
});

it('renders markdown tables inside a scrollable wrapper', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'table@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test(AiChatWidget::class)
        ->set('open', true)
        ->set('messages', [
            ['role' => 'assistant', 'content' => <<<'MD'
| Nombre | Estado |
| --- | --- |
| Ana | Activo |
MD],
        ])
        ->assertSee('<div class="pc-ai-chat__table"><table>', false)
        ->assertSee('Ana')
        ->assertSee('Activo');
});

it('escapes raw html tables in assistant messages', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'raw-table@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test(AiChatWidget::class)
        ->set('open', true)
        ->set('messages', [
            ['role' => 'assistant', 'content' => '<table><tr><td>secreto</td></tr></table>'],
        ])
        ->assertDontSee('<table>', false)
        ->assertSee('<table><tr><td>secreto</td></tr></table>');
});

it('escapes raw html in assistant messages', function () {
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'xss@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test(AiChatWidget::class)
        ->set('open', true)
        ->set('messages', [
            ['role' => 'assistant', 'content' => '<script>alert(1)</script>'],
        ])
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('<script>alert(1)</script>');
});
