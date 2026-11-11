<?php

declare(strict_types=1);

namespace Cbstian\AiChat\History;

use Cbstian\AiChat\Models\AiChatSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Recorta el historial que el proveedor va a recibir. No escribe en la base.
 */
final class ToolResultCompactor
{
    private const string StubPrefix = 'Resultado compactado de ';

    private const string CutMark = '… [recorte]';

    public function __construct(
        private readonly HistorySettings $settings,
    ) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    public function compact(string $conversationId, Collection $messages): Collection
    {
        if (! $this->settings->enabled()) {
            return $messages;
        }

        if ($this->settings->onlyChatSessions() && ! $this->isChatSession($conversationId)) {
            return $messages;
        }

        /** @var list<Message> $items */
        $items = [];

        foreach ($messages->values() as $message) {
            $items[] = clone $message;
        }

        $this->hidePreamble($items);

        $groups = $this->groups($items);
        $assistantIndexes = $this->assistantGroupIndexes($groups);
        $keep = $this->settings->keepFullTurns();
        $oldCount = max(0, count($assistantIndexes) - $keep);

        foreach (array_slice($assistantIndexes, 0, $oldCount) as $index) {
            $this->stubGroup($groups[$index], false);
        }

        $recent = $keep > 0 ? array_slice($assistantIndexes, -$keep) : [];

        foreach ($recent as $index) {
            $this->trimGroup($groups[$index]);
        }

        if ($this->settings->tokenBudget() > 0) {
            foreach ($recent as $index) {
                if (! $this->overBudget($groups)) {
                    break;
                }

                $this->stubGroup($groups[$index], true);
            }

            while ($this->overBudget($groups) && count($groups) > 1) {
                array_shift($groups);
            }
        }

        return new Collection($this->flatten($groups));
    }

    /**
     * Texto visible al recargar el widget. Sin steps, se usa la columna content.
     *
     * @param  array<int, mixed>|null  $steps
     */
    public function visibleAssistantText(?string $content, ?array $steps): string
    {
        $fallback = (string) $content;

        if (! $this->settings->hidePreToolText()) {
            return $fallback;
        }

        if ($steps === null || $steps === []) {
            return $fallback;
        }

        $parts = [];

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $calls = $step['tool_calls'] ?? [];

            if (is_array($calls) && $calls !== []) {
                continue;
            }

            $text = trim((string) ($step['content'] ?? ''));

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    private function isChatSession(string $conversationId): bool
    {
        if ($conversationId === '') {
            return false;
        }

        try {
            return AiChatSession::query()
                ->where('agent_conversation_id', $conversationId)
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @param  list<Message>  $messages
     */
    private function hidePreamble(array $messages): void
    {
        if (! $this->settings->hidePreToolText()) {
            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
                $message->content = '';
            }
        }
    }

    /**
     * @param  list<Message>  $messages
     * @return list<list<Message>>
     */
    private function groups(array $messages): array
    {
        /** @var list<list<Message>> $groups */
        $groups = [];
        /** @var list<Message> $current */
        $current = [];

        foreach ($messages as $message) {
            if ($this->isUser($message) && $this->containsAssistant($current)) {
                $groups[] = $current;
                $current = [];
            }

            $current[] = $message;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param  list<list<Message>>  $groups
     * @return list<int>
     */
    private function assistantGroupIndexes(array $groups): array
    {
        $indexes = [];

        foreach ($groups as $index => $group) {
            if ($this->containsAssistant($group)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  list<Message>  $group
     */
    private function containsAssistant(array $group): bool
    {
        foreach ($group as $message) {
            if (! $this->isUser($message)) {
                return true;
            }
        }

        return false;
    }

    private function isUser(Message $message): bool
    {
        return $message->role === MessageRole::User;
    }

    /**
     * @param  list<Message>  $group
     */
    private function stubGroup(array $group, bool $skipAlreadyStubbed): void
    {
        foreach ($group as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            $message->toolResults = $message->toolResults->map(
                function (ToolResult $result) use ($skipAlreadyStubbed): ToolResult {
                    if ($skipAlreadyStubbed && str_starts_with($result->text(), self::StubPrefix)) {
                        return $result;
                    }

                    return $this->replaceResult($result, $this->stubText($result));
                },
            );
        }
    }

    /**
     * @param  list<Message>  $group
     */
    private function trimGroup(array $group): void
    {
        $max = $this->settings->maxToolResultChars();

        if ($max <= 0) {
            return;
        }

        foreach ($group as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            $message->toolResults = $message->toolResults->map(
                function (ToolResult $result) use ($max): ToolResult {
                    $text = $result->text();

                    if (mb_strlen($text) <= $max) {
                        return $result;
                    }

                    return $this->replaceResult($result, $this->cut($text, $max));
                },
            );
        }
    }

    private function stubText(ToolResult $result): string
    {
        $limit = $this->settings->compactChars();

        if ($limit === 0) {
            return '';
        }

        $json = json_encode($result->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $arguments = is_string($json) ? $json : '{}';
        $prefix = self::StubPrefix.$result->name.' ('.mb_strlen($result->text()).' caracteres). Argumentos: ';
        $suffix = '. El detalle se usó en un turno anterior; si hace falta, vuelve a llamar la herramienta.';
        $room = $limit - mb_strlen($prefix) - mb_strlen($suffix);

        if ($room < 1) {
            return mb_substr($prefix.$arguments.$suffix, 0, $limit);
        }

        if (mb_strlen($arguments) > $room) {
            $arguments = $room === 1
                ? '…'
                : mb_substr($arguments, 0, $room - 1).'…';
        }

        $stub = $prefix.$arguments.$suffix;

        return mb_strlen($stub) <= $limit ? $stub : mb_substr($stub, 0, $limit);
    }

    private function cut(string $text, int $max): string
    {
        $markLength = mb_strlen(self::CutMark);

        if ($max <= $markLength) {
            return mb_substr(self::CutMark, 0, $max);
        }

        return mb_substr($text, 0, $max - $markLength).self::CutMark;
    }

    private function replaceResult(ToolResult $result, string $text): ToolResult
    {
        return new ToolResult(
            $result->id,
            $result->name,
            $result->arguments,
            $text,
            $result->resultId,
            $result->denied,
            $result->failed,
        );
    }

    /**
     * @param  list<list<Message>>  $groups
     */
    private function overBudget(array $groups): bool
    {
        $budget = $this->settings->tokenBudget();

        if ($budget <= 0) {
            return false;
        }

        return $this->tokens($groups) > $budget;
    }

    /**
     * @param  list<list<Message>>  $groups
     */
    private function tokens(array $groups): int
    {
        $chars = 0;

        foreach ($this->flatten($groups) as $message) {
            $chars += mb_strlen((string) $message->content);

            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $call) {
                    $chars += mb_strlen($call->name);
                    $json = json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $chars += is_string($json) ? mb_strlen($json) : 0;
                }
            }

            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $chars += mb_strlen($result->name) + mb_strlen($result->text());
                }
            }
        }

        return (int) ceil($chars / 4);
    }

    /**
     * @param  list<list<Message>>  $groups
     * @return list<Message>
     */
    private function flatten(array $groups): array
    {
        $flat = [];

        foreach ($groups as $group) {
            foreach ($group as $message) {
                $flat[] = $message;
            }
        }

        return $flat;
    }
}
