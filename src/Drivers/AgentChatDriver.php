<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Drivers;

use Cbstian\AiChat\Contracts\ChatDriver;
use Cbstian\AiChat\Contracts\ResolvesChatAccess;
use Cbstian\AiChat\Contracts\ResolvesChatAgent;
use Cbstian\AiChat\Dto\ChatTurnResult;
use Cbstian\AiChat\Models\AiChatSession;
use Cbstian\AiChat\Models\AiChatToolCallLog;
use Cbstian\AiChat\Models\AiChatTurnLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use RuntimeException;
use Throwable;

class AgentChatDriver implements ChatDriver
{
    public function __construct(
        protected ResolvesChatAgent $agents,
        protected ?ResolvesChatAccess $access = null,
    ) {}

    public function canAccess(?Authenticatable $user): bool
    {
        if (! config('ai-chat.enabled', true)) {
            return false;
        }

        if ($user === null) {
            return false;
        }

        return $this->access?->canAccess($user) ?? true;
    }

    public function ask(
        Authenticatable $user,
        string $prompt,
        ?string $conversationId,
        array $context,
        callable $onEvent,
    ): ChatTurnResult {
        if (! $this->canAccess($user)) {
            throw ValidationException::withMessages([
                'prompt' => 'No tienes acceso al chat IA.',
            ]);
        }

        $prompt = trim($prompt);
        $max = (int) config('ai-chat.max_prompt_length', 8000);

        if ($prompt === '' || mb_strlen($prompt) > $max) {
            throw ValidationException::withMessages([
                'prompt' => "El mensaje debe tener entre 1 y {$max} caracteres.",
            ]);
        }

        $rateKey = 'ai-chat:'.$user->getAuthIdentifier();
        $perMinute = (int) data_get(config('ai-chat.rate_limit'), 'per_minute', 20);

        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            throw ValidationException::withMessages([
                'prompt' => "Has alcanzado el límite de {$perMinute} mensajes por minuto.",
            ]);
        }

        RateLimiter::hit($rateKey, 60);

        $provider = config('ai-chat.provider') ?: config('ai.default');
        $model = config('ai-chat.model');
        $started = microtime(true);
        $text = '';
        $toolCalls = 0;
        $session = $this->resolveSession($user, $conversationId, $context);

        try {
            $agent = $this->agents->make($user, $context);

            if (! method_exists($agent, 'stream')) {
                throw new RuntimeException('El agente resuelto debe exponer stream().');
            }

            $conversationId = $session?->agent_conversation_id ?: $conversationId;

            if (filled($conversationId) && method_exists($agent, 'continue')) {
                $agent->continue($conversationId, as: $user);
            } elseif (method_exists($agent, 'forUser')) {
                $agent->forUser($user);
            }

            $stream = $agent->stream(
                $prompt,
                provider: $provider,
                model: filled($model) ? $model : null,
                timeout: 120,
            );

            foreach ($stream as $event) {
                if ($event instanceof ToolCall) {
                    $toolCalls++;
                    $this->logToolCall($session, $event);
                }

                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                }

                $onEvent($event);
            }

            $newConversationId = $stream->conversationId ?? $conversationId;

            if ($session !== null && filled($newConversationId) && blank($session->agent_conversation_id)) {
                $session->forceFill(['agent_conversation_id' => $newConversationId])->save();
            }

            $session?->touch();

            $usage = $stream->usage ?? null;

            $result = new ChatTurnResult(
                conversationId: $newConversationId,
                text: $text,
                promptTokens: (int) ($usage->promptTokens ?? 0),
                completionTokens: (int) ($usage->completionTokens ?? 0),
                toolCallCount: $toolCalls,
                durationMs: (int) round((microtime(true) - $started) * 1000),
                succeeded: true,
                provider: is_string($provider) ? $provider : null,
                model: filled($model) ? (string) $model : null,
            );

            $this->logTurn($session, $result, mb_strlen($prompt), mb_strlen($text));

            return $result;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $result = new ChatTurnResult(
                conversationId: $conversationId,
                toolCallCount: $toolCalls,
                durationMs: (int) round((microtime(true) - $started) * 1000),
                succeeded: false,
                errorMessage: mb_substr($exception->getMessage(), 0, 500),
                provider: is_string($provider) ? $provider : null,
                model: filled($model) ? (string) $model : null,
            );

            $this->logTurn($session, $result, mb_strlen($prompt), 0);

            throw $exception;
        }
    }

    public function messages(Authenticatable $user, ?string $conversationId, int $limit = 50): array
    {
        if (blank($conversationId) && config('ai-chat.persist', true)) {
            $session = AiChatSession::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->latest('updated_at')
                ->first();

            $conversationId = $session?->agent_conversation_id;
        }

        if (blank($conversationId) || ! interface_exists(ConversationStore::class)) {
            return [];
        }

        return array_values(app(ConversationStore::class)
            ->getLatestConversationMessages($conversationId, $limit)
            ->filter(fn (Message $message): bool => in_array($message->role, [MessageRole::User, MessageRole::Assistant], true)
                && filled($message->content))
            ->map(fn (Message $message): array => [
                'role' => $message->role->value,
                'content' => (string) $message->content,
            ])
            ->all());
    }

    public function startNew(Authenticatable $user, array $context = []): ?string
    {
        if (! config('ai-chat.persist', true)) {
            return null;
        }

        AiChatSession::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'agent_conversation_id' => null,
            'context' => $context ?: null,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function resolveSession(Authenticatable $user, ?string $conversationId, array $context): ?AiChatSession
    {
        if (! config('ai-chat.persist', true)) {
            return null;
        }

        if (filled($conversationId)) {
            $existing = AiChatSession::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('agent_conversation_id', $conversationId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if (blank($conversationId)) {
            $open = AiChatSession::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->whereNull('agent_conversation_id')
                ->latest('updated_at')
                ->first();

            if ($open !== null) {
                return $open;
            }
        }

        return AiChatSession::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'agent_conversation_id' => $conversationId,
            'context' => $context ?: null,
        ]);
    }

    protected function logTurn(?AiChatSession $session, ChatTurnResult $result, int $promptLength, int $responseLength): void
    {
        if ($session === null || ! config('ai-chat.verbose_logs', true)) {
            return;
        }

        AiChatTurnLog::query()->create([
            'ai_chat_session_id' => $session->id,
            'provider' => $result->provider,
            'model' => $result->model,
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'duration_ms' => $result->durationMs,
            'prompt_length' => $promptLength,
            'response_length' => $responseLength,
            'tool_call_count' => $result->toolCallCount,
            'succeeded' => $result->succeeded,
            'error_message' => $result->errorMessage,
        ]);
    }

    protected function logToolCall(?AiChatSession $session, ToolCall $event): void
    {
        if ($session === null || ! config('ai-chat.verbose_logs', true)) {
            return;
        }

        AiChatToolCallLog::query()->create([
            'ai_chat_turn_log_id' => null,
            'ai_chat_session_id' => $session->id,
            'tool_name' => $event->toolCall->name,
            'arguments_json' => json_encode($event->toolCall->arguments, JSON_UNESCAPED_UNICODE),
            'result_preview' => null,
            'duration_ms' => null,
        ]);
    }
}
