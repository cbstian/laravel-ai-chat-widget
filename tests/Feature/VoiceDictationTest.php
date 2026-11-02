<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Contracts\ResolvesChatAccess;
use Cbstian\AiChat\Contracts\SpeechToTextDriver;
use Cbstian\AiChat\Dto\SpeechAudio;
use Cbstian\AiChat\Dto\SpeechTranscript;
use Cbstian\AiChat\Livewire\AiChatWidget;
use Cbstian\AiChat\Speech\FilamentPanelId;
use Cbstian\AiChat\Speech\SpeechToTextManager;
use Cbstian\AiChat\Speech\VoiceLanguage;
use Cbstian\AiChat\Speech\VoiceTranscriptionException;
use Cbstian\AiChat\Speech\XaiSpeechToTextDriver;
use Cbstian\AiChat\Tests\Fixtures\DenyAllChatAccess;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Transcription;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

it('keeps voice off with the package defaults', function () {
    expect(config('ai-chat.voice.enabled'))->toBeFalse()
        ->and(config('ai-chat.voice.driver'))->toBe('browser')
        ->and(config('ai-chat.voice.language'))->toBe('es-CL')
        ->and(config('ai-chat.voice.auto_submit'))->toBeFalse()
        ->and(config('ai-chat.voice.xai.model'))->toBe('grok-voice-transcribe-2.0')
        ->and(config('ai-chat.voice.max_seconds'))->toBe(60)
        ->and(config('ai-chat.voice.max_kilobytes'))->toBe(2048);

    $user = voiceUser('voice-default@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertDontSee('pc-ai-chat__mic', false)
        ->assertDontSee('SpeechRecognition', false)
        ->assertDontSee('api.x.ai', false);
});

it('treats a published config without the voice block as disabled', function () {
    $aiChat = config('ai-chat');
    unset($aiChat['voice']);
    config(['ai-chat' => $aiChat]);

    $user = voiceUser('voice-published@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertDontSee('pc-ai-chat__mic', false)
        ->assertDontSee('SpeechRecognition', false);
});

it('treats a string false as disabled even if a driver is set', function () {
    config([
        'ai-chat.voice.enabled' => 'false',
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-should-stay-server-side',
    ]);

    $user = voiceUser('voice-string-false@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertDontSee('pc-ai-chat__mic', false)
        ->assertDontSee('sk-should-stay-server-side', false);
});

it('renders the browser microphone without upload urls or secrets', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
        'ai-chat.voice.language' => 'es-CL',
        'ai-chat.voice.xai.key' => 'sk-browser-must-not-leak',
        'ai-chat.voice.xai.model' => 'grok-voice-transcribe-2.0',
    ]);

    $user = voiceUser('voice-browser@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertSee('pc-ai-chat__mic', false)
        ->assertSee('es-CL', false)
        ->assertSee('SpeechRecognition', false)
        ->assertDontSee('api.x.ai', false)
        ->assertDontSee('sk-browser-must-not-leak', false)
        ->assertDontSee('grok-voice-transcribe-2.0', false)
        ->assertDontSee('laravel-ai', false);

    $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/ai-chat.css');

    expect($css)->toContain('.pc-ai-chat__mic');
});

it('hides the microphone when the page forces voice off', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
    ]);

    $user = voiceUser('voice-mount-off@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class, ['voice' => false])
        ->set('open', true)
        ->assertSet('voice', false)
        ->assertDontSee('pc-ai-chat__mic', false)
        ->assertDontSee('SpeechRecognition', false);
});

it('hides the microphone on a filament panel that is not allowed', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
        'ai-chat.voice.panels' => ['billing'],
    ]);

    app()->instance(FilamentPanelId::class, new class extends FilamentPanelId
    {
        public function current(): ?string
        {
            return 'admin';
        }
    });

    $user = voiceUser('voice-panel-hide@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertDontSee('pc-ai-chat__mic', false);
});

it('shows the microphone when the current panel is allowed', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
        'ai-chat.voice.panels' => ['admin'],
    ]);

    app()->instance(FilamentPanelId::class, new class extends FilamentPanelId
    {
        public function current(): ?string
        {
            return 'admin';
        }
    });

    $user = voiceUser('voice-panel-show@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertSee('pc-ai-chat__mic', false);
});

it('ignores the panel list outside filament', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
        'ai-chat.voice.panels' => ['billing'],
    ]);

    $user = voiceUser('voice-panel-outside@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertSee('pc-ai-chat__mic', false);
});

it('reports an unknown driver and does not fall back to the browser', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'acme-missing',
    ]);

    expect(fn () => app(SpeechToTextManager::class)->driver())
        ->toThrow(InvalidArgumentException::class);

    $user = voiceUser('voice-unknown@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->assertDontSee('pc-ai-chat__mic', false)
        ->assertDontSee('SpeechRecognition', false)
        ->assertSee('El dictado por voz no está disponible con el driver configurado.');
});

it('resolves a driver registered by the application', function () {
    $manager = app(SpeechToTextManager::class);

    $manager->extend('acme', fn (): SpeechToTextDriver => new class implements SpeechToTextDriver
    {
        public function runsInBrowser(): bool
        {
            return false;
        }

        public function transcribe(SpeechAudio $audio): SpeechTranscript
        {
            return new SpeechTranscript('desde acme');
        }
    });

    expect($manager->driver('acme')->transcribe(voiceAudio())->text)->toBe('desde acme')
        ->and($manager->driver('browser')->runsInBrowser())->toBeTrue();

    expect(fn () => $manager->driver('browser')->transcribe(voiceAudio()))
        ->toThrow(VoiceTranscriptionException::class);

    expect(fn () => $manager->driver('nope'))->toThrow(InvalidArgumentException::class);

    expect(method_exists(ChatDriver::class, 'transcribe'))->toBeFalse();
});

it('does not call a provider when the browser driver receives an upload', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'browser',
    ]);

    Transcription::fake()->preventStrayTranscriptions();
    Http::preventStrayRequests();

    $user = voiceUser('voice-browser-upload@example.test');

    $component = Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('prompt', 'escrito')
        ->set('audio', voiceUpload('audio/webm'));

    $audio = $component->get('audio');
    expect($audio)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($audio->exists())->toBeTrue();

    $component->call('transcribe')
        ->assertSet('prompt', 'escrito')
        ->assertSet('audio', null);

    expect($audio->exists())->toBeFalse();
    Http::assertNothingSent();
    Transcription::assertNothingGenerated();
});

it('fills the prompt from laravel ai and does not call xai', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'laravel-ai',
        'ai-chat.voice.language' => 'es-CL',
        'ai-chat.voice.auto_submit' => false,
        'ai-chat.voice.laravel-ai.provider' => 'openai',
        'ai-chat.voice.laravel-ai.model' => 'whisper-1',
        'ai-chat.voice.laravel-ai.diarize' => false,
    ]);

    Transcription::fake(['hola desde el audio'])->preventStrayTranscriptions();
    Http::fake();
    FakeChatAgent::fake(['no debe usarse']);

    $user = voiceUser('voice-laravel-ai@example.test');

    $component = Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('prompt', 'Hola')
        ->set('audio', voiceUpload('audio/webm'));

    $audio = $component->get('audio');

    $component->call('transcribe')
        ->assertSet('prompt', 'Hola hola desde el audio')
        ->assertSet('pendingQuestion', '')
        ->assertSet('error', null)
        ->assertSet('audio', null);

    expect($audio->exists())->toBeFalse();

    Transcription::assertGenerated(fn ($prompt): bool => $prompt->language === 'es'
        && $prompt->diarize === false
        && $prompt->model === 'whisper-1');
    Http::assertNothingSent();
    FakeChatAgent::assertNotPrompted(fn (): bool => true);

    expect(Livewire::actingAs($user)->test(AiChatWidget::class)->set('open', true)->html())
        ->not->toContain('whisper-1')
        ->not->toContain('openai');
});

it('accepts ai.default only when that provider can transcribe', function () {
    config([
        'ai-chat.voice.laravel-ai.provider' => null,
        'ai.default' => 'openai',
    ]);

    Transcription::fake(['atajo'])->preventStrayTranscriptions();

    $transcript = app(SpeechToTextManager::class)->driver('laravel-ai')->transcribe(voiceAudio());

    expect($transcript->text)->toBe('atajo');
    Transcription::assertGenerated(fn ($prompt): bool => $prompt->language === 'es');
});

it('does not call laravel ai when the default provider cannot transcribe', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'laravel-ai',
        'ai-chat.voice.laravel-ai.provider' => null,
        'ai.default' => 'xai',
    ]);

    Transcription::fake()->preventStrayTranscriptions();
    Http::preventStrayRequests();

    $user = voiceUser('voice-bad-provider@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('prompt', '')
        ->assertSet('error', 'Configura voice.laravel-ai.provider con un provider que transcriba audio.');

    Transcription::assertNothingGenerated();
});

it('submits the transcript only when auto submit is enabled', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'laravel-ai',
        'ai-chat.voice.laravel-ai.provider' => 'openai',
        'ai-chat.voice.auto_submit' => true,
    ]);

    Transcription::fake(['dictado listo'])->preventStrayTranscriptions();
    FakeChatAgent::fake(['respuesta']);

    $user = voiceUser('voice-auto-submit@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('pendingQuestion', 'dictado listo')
        ->assertSet('prompt', '');

    FakeChatAgent::assertNotPrompted(fn (): bool => true);
});

it('posts xai speech to text with the file last and maps es-CL to es', function () {
    config([
        'ai-chat.voice.language' => 'es-CL',
        'ai-chat.voice.xai.key' => 'sk-xai-test',
        'ai-chat.voice.xai.model' => 'grok-voice-transcribe-2.0',
        'ai-chat.voice.xai.format' => true,
        'ai-chat.voice.xai.filler_words' => false,
        'ai-chat.voice.xai.keyterms' => ['peso', '   ', str_repeat('a', 60), 12, 'uf'],
    ]);

    Http::fake([
        'https://api.x.ai/v1/stt' => Http::response([
            'text' => 'mil pesos',
            'language' => 'es-mx',
            'duration' => 1.5,
            'words' => [['text' => 'mil']],
        ]),
    ]);

    $transcript = app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio('webm-bytes'));

    expect($transcript->text)->toBe('mil pesos')
        ->and($transcript->language)->toBe('es-mx')
        ->and($transcript->duration)->toBe(1.5);

    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.x.ai/v1/stt')
            ->and($request->hasHeader('Authorization', 'Bearer sk-xai-test'))->toBeTrue();

        $parts = array_values($request->data());
        $names = array_map(fn (array $part): string => (string) $part['name'], $parts);

        expect($names)->toBe(['model', 'language', 'format', 'filler_words', 'keyterm', 'keyterm', 'keyterm', 'file'])
            ->and($parts[0]['contents'])->toBe('grok-voice-transcribe-2.0')
            ->and($parts[1]['contents'])->toBe('es')
            ->and($parts[2]['contents'])->toBe('true')
            ->and($parts[3]['contents'])->toBe('false')
            ->and($parts[4]['contents'])->toBe('peso')
            ->and($parts[5]['contents'])->toBe(str_repeat('a', 50))
            ->and($parts[6]['contents'])->toBe('uf')
            ->and($parts[7]['filename'])->toBe('audio.webm')
            ->and($parts[7]['contents'])->toBe('webm-bytes')
            ->and($names)->not->toContain('url');

        return true;
    });
});

it('keeps es-ES and drops format when xai has no language', function () {
    config([
        'ai-chat.voice.language' => 'es-ES',
        'ai-chat.voice.xai.key' => 'sk-xai-test',
        'ai-chat.voice.xai.format' => true,
    ]);

    Http::fake(['https://api.x.ai/v1/stt' => Http::response(['text' => 'uno'])]);

    app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio());

    Http::assertSent(function (Request $request): bool {
        $language = collect($request->data())->firstWhere('name', 'language');

        expect($language['contents'] ?? null)->toBe('es-ES');

        return true;
    });

    config(['ai-chat.voice.language' => '']);
    Http::fake(['https://api.x.ai/v1/stt' => Http::response(['text' => 'dos'])]);

    app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio());

    Http::assertSent(function (Request $request): bool {
        $names = array_map(fn (array $part): string => (string) $part['name'], array_values($request->data()));

        expect($names)->not->toContain('language')
            ->and($names)->not->toContain('format')
            ->and($names[array_key_last($names)])->toBe('file');

        return $request->url() === 'https://api.x.ai/v1/stt';
    });
});

it('limits xai keyterms to one hundred', function () {
    config([
        'ai-chat.voice.language' => 'es-CL',
        'ai-chat.voice.xai.key' => 'sk-xai-test',
        'ai-chat.voice.xai.keyterms' => array_map(fn (int $index): string => 't'.$index, range(1, 101)),
    ]);

    Http::fake(['https://api.x.ai/v1/stt' => Http::response(['text' => 'ok'])]);

    app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio());

    Http::assertSent(function (Request $request): bool {
        $terms = collect($request->data())->where('name', 'keyterm');

        expect($terms)->toHaveCount(100)
            ->and($terms->last()['contents'])->toBe('t100');

        return true;
    });
});

it('does not call xai without an api key', function () {
    config([
        'ai-chat.voice.xai.key' => null,
        'ai.providers.xai.key' => null,
    ]);

    Http::preventStrayRequests();

    expect(fn () => app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio()))
        ->toThrow(VoiceTranscriptionException::class);

    Http::assertNothingSent();
});

it('reuses the laravel ai xai key when voice.xai.key is empty', function () {
    config([
        'ai-chat.voice.xai.key' => null,
        'ai.providers.xai.key' => 'sk-from-ai-php',
        'ai-chat.voice.language' => 'es-MX',
    ]);

    Http::fake(['https://api.x.ai/v1/stt' => Http::response(['text' => 'clave reusada'])]);

    expect(app(XaiSpeechToTextDriver::class)->transcribe(voiceAudio())->text)->toBe('clave reusada');

    Http::assertSent(function (Request $request): bool {
        $language = collect($request->data())->firstWhere('name', 'language');

        return $request->hasHeader('Authorization', 'Bearer sk-from-ai-php')
            && ($language['contents'] ?? null) === 'es';
    });
});

it('hides upstream xai errors and the api key', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
        'ai-chat.voice.xai.model' => 'grok-voice-transcribe-2.0',
    ]);

    $user = voiceUser('voice-xai-html@example.test');

    expect(Livewire::actingAs($user)->test(AiChatWidget::class)->set('open', true)->html())
        ->toContain('pc-ai-chat__mic')
        ->not->toContain('sk-xai-secret')
        ->not->toContain('api.x.ai')
        ->not->toContain('grok-voice-transcribe-2.0')
        ->not->toContain('xai');

    Http::fake([
        'https://api.x.ai/v1/stt' => Http::response('upstream-secret-body', 401),
    ]);

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('error', 'No se pudo transcribir.')
        ->assertDontSee('upstream-secret-body', false)
        ->assertDontSee('sk-xai-secret', false);
});

it('tells the user when xai rate limits the transcription', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
    ]);

    Http::fake([
        'https://api.x.ai/v1/stt' => Http::response(['error' => 'slow down'], 429),
    ]);

    $user = voiceUser('voice-xai-429@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('error', 'Demasiadas transcripciones.')
        ->assertDontSee('slow down', false)
        ->assertDontSee('sk-xai-secret', false);
});

it('hides xai connection failures', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
    ]);

    Http::fake(function (): never {
        throw new ConnectionException('connection timed out sk-xai-secret');
    });

    $user = voiceUser('voice-xai-timeout@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('error', 'No se pudo transcribir.')
        ->assertDontSee('sk-xai-secret', false);
});

it('puts an xai transcript in the prompt and deletes the upload', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.language' => 'es-CL',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
        'ai-chat.voice.auto_submit' => false,
    ]);

    Http::fake([
        'https://api.x.ai/v1/stt' => Http::response(['text' => 'desde grok']),
    ]);

    $user = voiceUser('voice-xai-ok@example.test');

    $component = Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('open', true)
        ->set('audio', voiceUpload('audio/mp4', 'toma.mp4'));

    $audio = $component->get('audio');

    $component->call('transcribe')
        ->assertSet('prompt', 'desde grok')
        ->assertSet('pendingQuestion', '')
        ->assertSet('audio', null);

    expect($audio->exists())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $file = collect($request->data())->firstWhere('name', 'file');

        return ($file['filename'] ?? null) === 'audio.mp4';
    });
});

it('rejects a plain text upload without calling a provider', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
    ]);

    Http::fake();
    Transcription::fake()->preventStrayTranscriptions();

    $user = voiceUser('voice-mime@example.test');

    $component = Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', voiceUpload('text/plain', 'nota.txt'));

    $audio = $component->get('audio');

    $component->call('transcribe')
        ->assertSet('prompt', '')
        ->assertSet('error', 'El audio no se puede transcribir.')
        ->assertSet('audio', null);

    expect($audio->exists())->toBeFalse();
    Http::assertNothingSent();
    Transcription::assertNothingGenerated();
});

it('rejects audio over the size limit and deletes it', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'laravel-ai',
        'ai-chat.voice.laravel-ai.provider' => 'openai',
        'ai-chat.voice.max_kilobytes' => 2048,
    ]);

    Transcription::fake()->preventStrayTranscriptions();
    Http::preventStrayRequests();

    $user = voiceUser('voice-size@example.test');

    $component = Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', UploadedFile::fake()->create('grande.webm', 3000, 'audio/webm'));

    $audio = $component->get('audio');

    $component->call('transcribe')
        ->assertSet('error', 'El audio supera el tamaño permitido.')
        ->assertSet('audio', null);

    expect($audio->exists())->toBeFalse();
    Transcription::assertNothingGenerated();
});

it('rate limits transcriptions apart from chat messages', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'laravel-ai',
        'ai-chat.voice.laravel-ai.provider' => 'openai',
        'ai-chat.voice.rate_limit_per_minute' => 1,
    ]);

    $generated = 0;
    Transcription::fake(function () use (&$generated): string {
        $generated++;

        return 'una';
    })->preventStrayTranscriptions();

    $user = voiceUser('voice-rate@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('prompt', 'una')
        ->set('audio', voiceUpload('audio/webm', 'otra.webm'))
        ->call('transcribe')
        ->assertSet('error', 'Has alcanzado el límite de transcripciones por minuto.')
        ->assertSet('prompt', 'una');

    expect($generated)->toBe(1);
});

it('does not transcribe for a guest or a user without access', function () {
    config([
        'ai-chat.voice.enabled' => true,
        'ai-chat.voice.driver' => 'xai',
        'ai-chat.voice.xai.key' => 'sk-xai-secret',
    ]);

    Http::preventStrayRequests();

    Livewire::test(AiChatWidget::class)
        ->call('transcribe')
        ->assertSet('prompt', '')
        ->assertDontSee('pc-ai-chat__mic', false);

    config(['ai-chat.access' => DenyAllChatAccess::class]);
    app()->forgetInstance(ChatDriver::class);
    app()->forgetInstance(ResolvesChatAccess::class);

    $user = voiceUser('voice-denied@example.test');

    Livewire::actingAs($user)
        ->test(AiChatWidget::class)
        ->set('audio', voiceUpload('audio/webm'))
        ->call('transcribe')
        ->assertSet('prompt', '')
        ->assertDontSee('Asistente IA', false);

    Http::assertNothingSent();
});

it('maps voice languages for each driver', function () {
    config(['ai-chat.voice.language' => 'es-CL']);

    expect(VoiceLanguage::configured())->toBe('es-CL')
        ->and(VoiceLanguage::forLaravelAi())->toBe('es')
        ->and(VoiceLanguage::forXai())->toBe('es');

    config(['ai-chat.voice.language' => 'es-ES']);

    expect(VoiceLanguage::forLaravelAi())->toBe('es')
        ->and(VoiceLanguage::forXai())->toBe('es-ES');

    config(['ai-chat.voice.language' => 'en_US']);

    expect(VoiceLanguage::forLaravelAi())->toBe('en')
        ->and(VoiceLanguage::forXai())->toBe('en');

    config(['ai-chat.voice.language' => '']);

    expect(VoiceLanguage::forLaravelAi())->toBeNull()
        ->and(VoiceLanguage::forXai())->toBeNull();
});

function voiceUser(string $email): User
{
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => $email,
    ]);

    return $user;
}

function voiceUpload(string $mime, string $name = 'dictado.webm'): UploadedFile
{
    return UploadedFile::fake()->create($name, 8, $mime);
}

function voiceAudio(string $contents = 'audio'): SpeechAudio
{
    $path = tempnam(sys_get_temp_dir(), 'ai-chat-voice');

    if ($path === false) {
        throw new RuntimeException('No se pudo crear el audio temporal.');
    }

    file_put_contents($path, $contents);
    $GLOBALS['ai_chat_voice_files'][] = $path;

    return new SpeechAudio($path, 'audio/webm', 'audio.webm', strlen($contents));
}

afterEach(function () {
    foreach ($GLOBALS['ai_chat_voice_files'] ?? [] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }

    $GLOBALS['ai_chat_voice_files'] = [];
});
