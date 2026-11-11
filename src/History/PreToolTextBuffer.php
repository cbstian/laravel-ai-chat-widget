<?php

declare(strict_types=1);

namespace Cbstian\AiChat\History;

/**
 * Retiene el texto de un step hasta saber si era un preámbulo de una tool.
 *
 * laravel/ai no emite un fin de step. El límite de step es el StreamStart
 * siguiente (el mismo corte que usa TextDelta::combine) y StreamEnd cierra el turno.
 */
final class PreToolTextBuffer
{
    public const int ReleaseAt = 160;

    private string $buffer = '';

    private bool $released = false;

    public function push(string $delta): ?string
    {
        if ($this->released) {
            return $delta;
        }

        $this->buffer .= $delta;

        if (mb_strlen($this->buffer) >= self::ReleaseAt || str_contains($this->buffer, "\n\n")) {
            return $this->release();
        }

        return null;
    }

    public function discard(): void
    {
        $this->buffer = '';
        $this->released = false;
    }

    public function finishStep(): ?string
    {
        $text = ($this->released || $this->buffer === '') ? null : $this->buffer;
        $this->buffer = '';
        $this->released = false;

        return $text;
    }

    private function release(): string
    {
        $text = $this->buffer;
        $this->buffer = '';
        $this->released = true;

        return $text;
    }
}
