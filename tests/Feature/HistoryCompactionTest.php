<?php

declare(strict_types=1);

use Cbstian\AiChat\AiChatServiceProvider;
use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Dto\ChatTurnResult;
use Cbstian\AiChat\History\HistorySettings;
use Cbstian\AiChat\History\LanguageSuffixGateway;
use Cbstian\AiChat\History\PreToolTextBuffer;
use Cbstian\AiChat\Models\AiChatSession;
use Cbstian\AiChat\Tests\Fixtures\FakeChatAgent;
use Cbstian\AiChat\Tests\Fixtures\RecordingAiChatWidget;
use Cbstian\AiChat\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Livewire\Livewire;

function enableHistory(array $overrides = []): void
{
    foreach ($overrides as $key => $value) {
        config(['ai-chat.history.'.$key => $value]);
    }

    config(['ai-chat.history.enabled' => $overrides['enabled'] ?? true]);

    foreach (app()->getProviders(AiChatServiceProvider::class) as $provider) {
        if ($provider instanceof AiChatServiceProvider) {
            $provider->registerCompactingStore();
        }
    }
}

function historyId(): string
{
    static $n = 0;
    $n++;

    return sprintf('01900000-0000-7000-8000-%012d', $n);
}

function historyConversation(): string
{
    $id = historyId();

    Conversation::query()->create([
        'id' => $id,
        'title' => 'Historial',
    ]);

    return $id;
}

/**
 * @param  array<int, array<string, mixed>>  $steps
 */
function historyRow(string $conversationId, string $role, string $content, array $steps = []): void
{
    ConversationMessage::query()->create([
        'id' => historyId(),
        'conversation_id' => $conversationId,
        'agent' => 'test-agent',
        'role' => $role,
        'content' => $content,
        'attachments' => [],
        'steps' => $steps,
        'usage' => [],
        'meta' => [],
        'status' => MessageStatus::Completed,
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function toolStep(string $id, string $name, array $arguments, string $result, string $content = ''): array
{
    return [
        'content' => $content,
        'tool_calls' => [[
            'id' => $id,
            'name' => $name,
            'arguments' => $arguments,
            'result' => $result,
        ]],
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ];
}

/**
 * @param  list<array{id: string, name: string, arguments: array<string, mixed>, result: string}>  $calls
 * @return array<string, mixed>
 */
function multiToolStep(array $calls, string $content = ''): array
{
    return [
        'content' => $content,
        'tool_calls' => array_map(fn (array $call): array => [
            'id' => $call['id'],
            'name' => $call['name'],
            'arguments' => $call['arguments'],
            'result' => $call['result'],
        ], $calls),
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function textStep(string $content): array
{
    return [
        'content' => $content,
        'tool_calls' => [],
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ];
}

function linkChat(string $conversationId): User
{
    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'history-'.historyId().'@example.test',
    ]);

    AiChatSession::query()->create([
        'user_id' => $user->id,
        'agent_conversation_id' => $conversationId,
    ]);

    return $user;
}

/**
 * @param  Collection<int, Message>  $messages
 */
function replayBlob(Collection $messages): string
{
    $parts = [];

    foreach ($messages as $message) {
        $parts[] = (string) $message->content;

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                $parts[] = $call->id.'|'.$call->name.'|'.json_encode($call->arguments);
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $parts[] = ($result->resultId ?? $result->id).'|'.$result->name.'|'.$result->text();
            }
        }
    }

    return implode("\n", $parts);
}

/**
 * @param  Collection<int, Message>  $messages
 */
function assertToolPairs(Collection $messages): void
{
    $calls = [];
    $results = [];

    foreach ($messages as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                $calls[] = [$call->resultId ?? $call->id, $call->name, $call->arguments];
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $results[] = [$result->resultId ?? $result->id, $result->name, $result->arguments];
            }
        }
    }

    expect($results)->toBe($calls)
        ->and($calls)->not->toBe([]);
}

function seedTwoTurns(string $oldResult, string $recentResult = 'resultado reciente'): string
{
    $id = historyConversation();

    historyRow($id, 'user', 'primera pregunta');
    historyRow($id, 'assistant', "I'll check that for you.\n\nListo viejo.", [
        toolStep('call_old', 'ReadGuide', ['path' => 'guia.md'], $oldResult, "I'll check that for you."),
        textStep('Listo viejo.'),
    ]);
    historyRow($id, 'user', 'segunda pregunta');
    historyRow($id, 'assistant', 'Listo reciente.', [
        toolStep('call_new', 'ReadGuide', ['path' => 'corta.md'], $recentResult, ''),
        textStep('Listo reciente.'),
    ]);

    return $id;
}

it('keeps the full tool result when history is missing or disabled', function () {
    $payload = str_repeat('A', 4000);
    $id = seedTwoTurns($payload);

    $stored = config('ai-chat');
    unset($stored['history']);
    config(['ai-chat' => $stored]);

    expect(app(HistorySettings::class)->enabled())->toBeFalse()
        ->and(app(ConversationStore::class))->toBeInstanceOf(DatabaseConversationStore::class);

    $blob = replayBlob(app(ConversationStore::class)->getLatestConversationMessages($id, 100));

    expect($blob)->toContain($payload);

    config(['ai-chat.history.enabled' => false]);

    foreach (app()->getProviders(AiChatServiceProvider::class) as $provider) {
        if ($provider instanceof AiChatServiceProvider) {
            $provider->registerCompactingStore();
        }
    }

    $blob = replayBlob(app(ConversationStore::class)->getLatestConversationMessages($id, 100));

    expect($blob)->toContain($payload)
        ->and(app(ConversationStore::class))->toBeInstanceOf(DatabaseConversationStore::class);
});

it('stubs old tool results and keeps the latest turn intact', function () {
    $payload = str_repeat('A', 4000);
    $id = seedTwoTurns($payload);
    linkChat($id);
    enableHistory(['keep_full_tool_results_for_turns' => 1]);

    $messages = app(ConversationStore::class)->getLatestConversationMessages($id, 100);
    $blob = replayBlob($messages);

    expect($blob)->not->toContain($payload)
        ->and($blob)->toContain('Resultado compactado de ReadGuide (4000 caracteres)')
        ->and($blob)->toContain('guia.md')
        ->and($blob)->toContain('resultado reciente')
        ->and($blob)->toContain('call_old|ReadGuide')
        ->and($blob)->toContain('call_new|ReadGuide');

    assertToolPairs($messages);

    $assistant = ConversationMessage::query()
        ->where('conversation_id', $id)
        ->where('role', 'assistant')
        ->orderBy('id')
        ->first();

    expect($assistant)->not->toBeNull()
        ->and($assistant->steps[0]['tool_calls'][0]['result'])->toBe($payload)
        ->and($assistant->content)->toContain("I'll check that for you.");
});

it('compacts every tool result from one old turn', function () {
    $id = historyConversation();
    linkChat($id);

    historyRow($id, 'user', 'tres tools');
    historyRow($id, 'assistant', 'busqué tres cosas', [
        multiToolStep([
            ['id' => 'c1', 'name' => 'ReadGuide', 'arguments' => ['path' => 'a.md'], 'result' => str_repeat('A', 500)],
            ['id' => 'c2', 'name' => 'ReadGuide', 'arguments' => ['path' => 'b.md'], 'result' => str_repeat('B', 500)],
            ['id' => 'c3', 'name' => 'ListDocs', 'arguments' => ['dir' => 'docs'], 'result' => str_repeat('C', 500)],
        ], "I'll check that for you."),
    ]);
    historyRow($id, 'user', 'gracias');
    historyRow($id, 'assistant', 'de nada', [textStep('de nada')]);

    enableHistory(['keep_full_tool_results_for_turns' => 1]);

    $messages = app(ConversationStore::class)->getLatestConversationMessages($id, 100);
    $blob = replayBlob($messages);

    expect($blob)->not->toContain(str_repeat('A', 500))
        ->and($blob)->not->toContain(str_repeat('B', 500))
        ->and($blob)->not->toContain(str_repeat('C', 500))
        ->and(substr_count($blob, 'Resultado compactado de'))->toBe(3);

    assertToolPairs($messages);
});

it('drops the preamble before a tool call and keeps the final answer', function () {
    $id = historyConversation();
    linkChat($id);

    historyRow($id, 'user', '¿qué dice la guía?');
    historyRow($id, 'assistant', "I'll check that for you.\n\nLa guía está lista.", [
        toolStep('call_1', 'ReadGuide', ['path' => 'guia.md'], str_repeat('Z', 100), "I'll check that for you."),
        textStep('La guía está lista.'),
    ]);

    enableHistory();

    $messages = app(ConversationStore::class)->getLatestConversationMessages($id, 100);
    $assistants = $messages->filter(fn (Message $message): bool => $message instanceof AssistantMessage)->values();

    expect($assistants)->toHaveCount(2)
        ->and($assistants[0]->content)->toBe('')
        ->and($assistants[0]->toolCalls)->not->toBeEmpty()
        ->and($assistants[1]->content)->toBe('La guía está lista.');

    $user = User::query()->first();

    expect(app(ChatDriver::class)->messages($user, $id))->toBe([
        ['role' => 'user', 'content' => '¿qué dice la guía?'],
        ['role' => 'assistant', 'content' => 'La guía está lista.'],
    ]);
});

it('falls back to the content column when a completed turn has no steps', function () {
    $id = historyConversation();
    historyRow($id, 'user', 'hola');
    historyRow($id, 'assistant', 'respuesta guardada', []);

    enableHistory();

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'fallback-'.historyId().'@example.test',
    ]);

    expect(app(ChatDriver::class)->messages($user, $id))->toBe([
        ['role' => 'user', 'content' => 'hola'],
        ['role' => 'assistant', 'content' => 'respuesta guardada'],
    ]);
});

it('stubs the oldest tool results first when the token budget is low', function () {
    $id = historyConversation();
    linkChat($id);

    foreach (['OLD' => str_repeat('A', 4000), 'MID' => str_repeat('B', 80), 'NEW' => str_repeat('C', 80)] as $label => $result) {
        historyRow($id, 'user', 'pregunta '.$label);
        historyRow($id, 'assistant', 'respuesta '.$label, [
            toolStep('call_'.$label, 'ReadGuide', ['path' => $label.'.md'], $result),
        ]);
    }

    enableHistory([
        'keep_full_tool_results_for_turns' => 3,
        'token_budget' => 500,
    ]);

    $messages = app(ConversationStore::class)->getLatestConversationMessages($id, 100);
    $blob = replayBlob($messages);

    expect($blob)->not->toContain(str_repeat('A', 4000))
        ->and($blob)->toContain('Resultado compactado de ReadGuide (4000 caracteres)')
        ->and($blob)->toContain(str_repeat('B', 80))
        ->and($blob)->toContain(str_repeat('C', 80));

    assertToolPairs($messages);
});

it('drops oldest pairs under a tiny budget without orphaning a tool call', function () {
    $id = historyConversation();
    linkChat($id);

    foreach (['uno', 'dos', 'tres'] as $label) {
        historyRow($id, 'user', 'marca-usuario-'.$label);
        historyRow($id, 'assistant', 'marca-asistente-'.$label, [
            toolStep('call_'.$label, 'ReadGuide', ['path' => $label.'.md'], str_repeat($label, 2000)),
        ]);
    }

    enableHistory([
        'keep_full_tool_results_for_turns' => 3,
        'token_budget' => 20,
    ]);

    $messages = app(ConversationStore::class)->getLatestConversationMessages($id, 100);
    $blob = replayBlob($messages);

    expect($blob)->not->toContain('marca-usuario-uno')
        ->and($blob)->not->toContain('marca-usuario-dos')
        ->and($blob)->toContain('marca-usuario-tres');

    assertToolPairs($messages);
});

it('leaves conversations outside ai_chat_sessions untouched', function () {
    $payload = str_repeat('Q', 4000);
    $outside = seedTwoTurns($payload, 'reciente fuera');
    $inside = seedTwoTurns($payload, 'reciente dentro');
    linkChat($inside);

    enableHistory(['keep_full_tool_results_for_turns' => 1]);

    $outsideBlob = replayBlob(app(ConversationStore::class)->getLatestConversationMessages($outside, 100));
    $insideBlob = replayBlob(app(ConversationStore::class)->getLatestConversationMessages($inside, 100));

    expect($outsideBlob)->toContain($payload)
        ->and($insideBlob)->not->toContain($payload)
        ->and($insideBlob)->toContain('reciente dentro');
});

it('does not stream a short english preamble once a tool call arrives', function () {
    config([
        'ai-chat.history.enabled' => true,
        'ai-chat.tool_labels.ReadGuide' => 'Leyendo la guía',
    ]);

    $events = [
        new StreamStart('s1', 'openai', 'gpt-4o-mini', time()),
        new TextDelta('d1', 'm1', "I'll check that for you.", time()),
        new ToolCall('t1', new ToolCallData('call_1', 'ReadGuide', ['path' => 'guia.md']), time()),
        new StreamStart('s2', 'openai', 'gpt-4o-mini', time()),
        new TextDelta('d2', 'm2', 'Listo.', time()),
        new StreamEnd('e1', 'stop', new TextUsage, time()),
    ];

    app()->instance(ChatDriver::class, new class($events) implements ChatDriver
    {
        /**
         * @param  list<object>  $events
         */
        public function __construct(private array $events) {}

        public function canAccess(?Authenticatable $user): bool
        {
            return $user !== null;
        }

        public function ask(Authenticatable $user, string $prompt, ?string $conversationId, array $context, callable $onEvent): ChatTurnResult
        {
            foreach ($this->events as $event) {
                $onEvent($event);
            }

            return new ChatTurnResult(conversationId: 'conv-livewire');
        }

        public function messages(Authenticatable $user, ?string $conversationId, int $limit = 50): array
        {
            return [];
        }

        public function activeConversationId(Authenticatable $user): ?string
        {
            return null;
        }

        public function startNew(Authenticatable $user, array $context = []): ?string
        {
            return null;
        }
    });

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'stream-preamble@example.test',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(RecordingAiChatWidget::class)
        ->set('pendingQuestion', 'hola')
        ->call('ask')
        ->assertSet('error', null);

    $output = implode('', array_column($component->instance()->streamed, 'content'));

    expect($output)->toContain('Leyendo la guía')
        ->and($output)->toContain('Listo.')
        ->and($output)->not->toContain("I'll check that for you.")
        ->and($output)->not->toContain('I&#039;ll check');
});

it('streams a long answer that never calls a tool', function () {
    config(['ai-chat.history.enabled' => true]);

    $long = str_repeat('respuesta-larga ', 20).'COLA-STREAMEADA';

    expect(mb_strlen($long))->toBeGreaterThan(PreToolTextBuffer::ReleaseAt);

    $events = [
        new StreamStart('s1', 'openai', 'gpt-4o-mini', time()),
        new TextDelta('d1', 'm1', mb_substr($long, 0, 100), time()),
        new TextDelta('d2', 'm1', mb_substr($long, 100), time()),
        new StreamEnd('e1', 'stop', new TextUsage, time()),
    ];

    app()->instance(ChatDriver::class, new class($events) implements ChatDriver
    {
        /**
         * @param  list<object>  $events
         */
        public function __construct(private array $events) {}

        public function canAccess(?Authenticatable $user): bool
        {
            return $user !== null;
        }

        public function ask(Authenticatable $user, string $prompt, ?string $conversationId, array $context, callable $onEvent): ChatTurnResult
        {
            foreach ($this->events as $event) {
                $onEvent($event);
            }

            return new ChatTurnResult(conversationId: 'conv-long');
        }

        public function messages(Authenticatable $user, ?string $conversationId, int $limit = 50): array
        {
            return [];
        }

        public function activeConversationId(Authenticatable $user): ?string
        {
            return null;
        }

        public function startNew(Authenticatable $user, array $context = []): ?string
        {
            return null;
        }
    });

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'stream-long@example.test',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(RecordingAiChatWidget::class)
        ->set('pendingQuestion', 'hola')
        ->call('ask');

    $chunks = array_column($component->instance()->streamed, 'content');
    $output = implode('', $chunks);

    expect($chunks)->not->toBeEmpty()
        ->and($output)->toContain('respuesta-larga')
        ->and($output)->toContain('COLA-STREAMEADA');
});

it('appends the language line without wrapping the agent class', function () {
    $settings = new HistorySettings;
    config([
        'ai-chat.history.enabled' => true,
        'ai-chat.history.force_language' => 'español',
    ]);

    $gateway = new LanguageSuffixGateway(
        new class implements StepTextGateway
        {
            public function generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext): never
            {
                throw new RuntimeException('unused');
            }

            public function generateStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext): Generator
            {
                yield from [];
            }
        },
        'español',
        $settings,
    );

    expect($gateway->withLanguage('Eres el asistente.'))
        ->toBe("Eres el asistente.\n\nResponde siempre en español, también el texto que emites antes de usar una herramienta.");

    FakeChatAgent::fake(['Respuesta con idioma.']);

    $user = User::query()->create([
        'name' => 'Seba',
        'email' => 'idioma@example.test',
    ]);

    $result = app(ChatDriver::class)->ask($user, 'Hola', null, [], fn () => null);

    expect($result->text)->toBe('Respuesta con idioma.');
    FakeChatAgent::assertPrompted('Hola');
});
