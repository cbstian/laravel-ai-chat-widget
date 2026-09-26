<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Models\AiChatSession;
use Cbstian\AiChat\Models\AiChatTurnLog;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\User;
use Laravel\Ai\Streaming\Events\TextDelta;

it('streams a fake agent reply and persists verbose turn logs', function () {
    FakeChatAgent::fake([
        'Respuesta simulada del asistente.',
    ]);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'fake@example.test',
    ]);

    $events = [];

    $result = app(ChatDriver::class)->ask(
        $user,
        '¿Cómo estás?',
        null,
        ['source' => 'pest'],
        function (object $event) use (&$events): void {
            $events[] = $event;
        },
    );

    expect($result->succeeded)->toBeTrue()
        ->and($result->text)->toBe('Respuesta simulada del asistente.')
        ->and($result->provider)->toBe('openai')
        ->and($result->model)->toBe('gpt-4o-mini')
        ->and($result->toolCallCount)->toBe(0);

    expect(collect($events)->contains(fn ($e) => $e instanceof TextDelta))->toBeTrue();

    FakeChatAgent::assertPromptedTimes(1);
    FakeChatAgent::assertPrompted('¿Cómo estás?');

    expect(AiChatSession::query()->count())->toBe(1);
    expect(AiChatTurnLog::query()->count())->toBe(1);

    $log = AiChatTurnLog::query()->first();

    expect($log->succeeded)->toBeTrue()
        ->and($log->provider)->toBe('openai')
        ->and($log->model)->toBe('gpt-4o-mini')
        ->and($log->response_length)->toBe(mb_strlen('Respuesta simulada del asistente.'));
});

it('skips verbose logs when disabled', function () {
    config(['ai-chat.verbose_logs' => false]);

    FakeChatAgent::fake(['Ok.']);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'novlog@example.test',
    ]);

    app(ChatDriver::class)->ask($user, 'Hola', null, [], fn () => null);

    expect(AiChatSession::query()->count())->toBe(1);
    expect(AiChatTurnLog::query()->count())->toBe(0);
});

it('does not create sessions when persist is disabled', function () {
    config(['ai-chat.persist' => false]);

    FakeChatAgent::fake(['Sin persistencia.']);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'nopersist@example.test',
    ]);

    $result = app(ChatDriver::class)->ask($user, 'Ping', null, [], fn () => null);

    expect($result->succeeded)->toBeTrue()
        ->and($result->text)->toBe('Sin persistencia.');

    expect(AiChatSession::query()->count())->toBe(0);
    expect(AiChatTurnLog::query()->count())->toBe(0);
});
