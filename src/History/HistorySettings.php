<?php

declare(strict_types=1);

namespace Cbstian\AiChat\History;

/**
 * Lee `ai-chat.history` con defaults. Si la clave no existe, todo queda apagado.
 */
final class HistorySettings
{
    public function enabled(): bool
    {
        return $this->bool('enabled', false);
    }

    public function onlyChatSessions(): bool
    {
        return $this->bool('only_chat_sessions', true);
    }

    public function keepFullTurns(): int
    {
        return max(0, $this->int('keep_full_tool_results_for_turns', 1));
    }

    public function compactChars(): int
    {
        return max(0, $this->int('compact_tool_result_chars', 240));
    }

    public function maxToolResultChars(): int
    {
        return max(0, $this->int('max_tool_result_chars', 0));
    }

    public function tokenBudget(): int
    {
        return max(0, $this->int('token_budget', 0));
    }

    public function hidePreToolText(): bool
    {
        return $this->enabled() && $this->bool('hide_pre_tool_text', true);
    }

    public function forceLanguage(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $value = data_get(config('ai-chat.history'), 'force_language');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function languageLine(string $language): string
    {
        return 'Responde siempre en '.$language.', también el texto que emites antes de usar una herramienta.';
    }

    private function bool(string $key, bool $default): bool
    {
        $history = config('ai-chat.history');

        if (! is_array($history) || ! array_key_exists($key, $history)) {
            return $default;
        }

        $value = $history[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function int(string $key, int $default): int
    {
        $history = config('ai-chat.history');

        if (! is_array($history) || ! array_key_exists($key, $history)) {
            return $default;
        }

        $value = $history[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }
}
