<?php

declare(strict_types=1);

use Cbstian\AiChat\Support\Assets;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Livewire\Livewire;

// Livewire guarda en estáticos los @assets ya emitidos. Cada request real
// empieza limpio; en la suite hay que limpiarlo a mano entre tests.
beforeEach(fn () => Livewire::flushState());

function assetsUser(string $email): User
{
    return User::query()->create([
        'name' => 'Seba',
        'email' => $email,
    ]);
}

function registerWidgetPage(): void
{
    Route::get('/assets-page', fn (): string => Blade::render(
        "<!DOCTYPE html>\n<html><head><title>Demo</title>@aiChatStyles</head><body>@livewire('ai-chat-widget')</body></html>",
    ))->middleware('web');
}

it('serves the voice script as javascript', function () {
    $response = $this->get(Assets::voiceScriptUrl());

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/javascript')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))
        ->toContain("if (typeof window.pcAiChatVoice !== 'function')")
        ->toContain('window.pcAiChatVoice = function (settings)')
        ->not->toContain('<script');
});

it('caches an unversioned voice script url only briefly', function () {
    $response = $this->get('/ai-chat/assets/voice.js');

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('max-age=300')
        ->not->toContain('immutable');
});

it('versions the voice script url with the file hash', function () {
    expect(Assets::voiceScriptUrl())
        ->toBe('http://localhost/ai-chat/assets/voice.js?id='.substr((string) md5_file(Assets::voiceScriptPath()), 0, 12));
});

it('loads the voice script once in the head instead of inline', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
    ]);

    registerWidgetPage();

    $html = $this->actingAs(assetsUser('assets-head@example.test'))
        ->get('/assets-page')
        ->assertOk()
        ->getContent();

    $head = (string) strstr((string) $html, '</head>', true);
    $tag = '<script src="'.e(Assets::voiceScriptUrl()).'"></script>';

    expect($head)->toContain($tag)
        ->and(substr_count((string) $html, 'ai-chat/assets/voice.js'))->toBe(1)
        ->and($html)->not->toContain('window.pcAiChatVoice')
        ->and($html)->toContain('pc-ai-chat__fab');
});

it('does not load the voice script when voice is off', function () {
    registerWidgetPage();

    $html = $this->actingAs(assetsUser('assets-off@example.test'))
        ->get('/assets-page')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('pc-ai-chat__fab')
        ->not->toContain('ai-chat/assets/voice.js');
});

it('adds the app csp nonce to the voice script and the styles', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
    ]);

    Vite::useCspNonce('nonce-de-prueba');

    registerWidgetPage();

    $html = $this->actingAs(assetsUser('assets-nonce@example.test'))
        ->get('/assets-page')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<script src="'.e(Assets::voiceScriptUrl()).'" nonce="nonce-de-prueba"></script>')
        ->toContain('<style nonce="nonce-de-prueba">/*')
        ->not->toContain('<style>/*');
});

it('renders the styles without a nonce when the app has none', function () {
    expect(Blade::render('@aiChatStyles'))->toStartWith('<style>')
        ->toContain('.pc-ai-chat__mic')
        ->toEndWith('</style>');
});
