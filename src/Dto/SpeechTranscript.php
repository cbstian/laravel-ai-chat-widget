<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Dto;

final class SpeechTranscript
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $language = null,
        public readonly ?float $duration = null,
    ) {}
}
