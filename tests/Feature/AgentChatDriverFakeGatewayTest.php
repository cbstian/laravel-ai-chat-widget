<?php

declare(strict_types=1);

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Models\AiChatSession;
use Cbstian\AiChat\Models\AiChatTurnLog;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
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
        ->and($result->toolCallCount)->toBe(0)
        ->and($result->promptTokens)->toBe(0)
        ->and($result->completionTokens)->toBe(0)
        ->and($result->conversationId)->not->toBeNull();

    ConversationMessage::query()->create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $result->conversationId,
        'agent' => FakeChatAgent::class,
        'role' => 'assistant',
        'content' => 'Este turno falló.',
        'attachments' => [],
        'steps' => [],
        'usage' => [],
        'meta' => ['error' => 'boom'],
        'status' => MessageStatus::Failed,
    ]);

    expect(app(ChatDriver::class)->messages($user, $result->conversationId))->toBe([
        ['role' => 'user', 'content' => '¿Cómo estás?'],
        ['role' => 'assistant', 'content' => 'Respuesta simulada del asistente.'],
    ]);

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

it('continues the visible conversation when the next message has no conversation id', function () {
    FakeChatAgent::fake([
        'Primera respuesta.',
        'Segunda respuesta.',
    ]);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'continue@example.test',
    ]);

    $driver = app(ChatDriver::class);

    $first = $driver->ask($user, 'Primer mensaje', null, [], fn () => null);

    // Igual que el widget al remontarse: muestra el historial, pero el id en memoria vuelve a null.
    expect($driver->messages($user, null))->toBe([
        ['role' => 'user', 'content' => 'Primer mensaje'],
        ['role' => 'assistant', 'content' => 'Primera respuesta.'],
    ]);

    $second = $driver->ask($user, 'Segundo mensaje', null, [], fn () => null);

    expect($second->conversationId)->toBe($first->conversationId)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(AiChatSession::query()->count())->toBe(1)
        ->and($driver->messages($user, null))->toBe([
            ['role' => 'user', 'content' => 'Primer mensaje'],
            ['role' => 'assistant', 'content' => 'Primera respuesta.'],
            ['role' => 'user', 'content' => 'Segundo mensaje'],
            ['role' => 'assistant', 'content' => 'Segunda respuesta.'],
        ]);
});

it('starts a separate conversation after startNew', function () {
    FakeChatAgent::fake([
        'Primera respuesta.',
        'Conversación nueva.',
    ]);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'new-chat@example.test',
    ]);

    $driver = app(ChatDriver::class);

    $first = $driver->ask($user, 'Primer mensaje', null, [], fn () => null);
    $driver->startNew($user);
    $second = $driver->ask($user, 'Otro tema', null, [], fn () => null);

    expect($second->conversationId)->not->toBe($first->conversationId)
        ->and(Conversation::query()->count())->toBe(2)
        ->and($driver->messages($user, null))->toBe([
            ['role' => 'user', 'content' => 'Otro tema'],
            ['role' => 'assistant', 'content' => 'Conversación nueva.'],
        ])
        ->and($driver->messages($user, $first->conversationId))->toBe([
            ['role' => 'user', 'content' => 'Primer mensaje'],
            ['role' => 'assistant', 'content' => 'Primera respuesta.'],
        ]);
});

it('does not query ai_chat_sessions when persist is disabled and there is no conversation', function () {
    config(['ai-chat.persist' => false]);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'nopersist-messages@example.test',
    ]);

    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(app(ChatDriver::class)->messages($user, null))->toBe([]);

    expect(collect($queries)->contains(
        fn (string $sql): bool => str_contains($sql, 'ai_chat_sessions'),
    ))->toBeFalse();
});
