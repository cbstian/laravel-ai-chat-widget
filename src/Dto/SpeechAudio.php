<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Dto;

final class SpeechAudio
{
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly string $filename,
        public readonly int $bytes,
        public readonly ?float $clientDurationSeconds = null,
    ) {}
}
