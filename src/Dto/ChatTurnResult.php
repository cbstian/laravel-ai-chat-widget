<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Dto;

final class ChatTurnResult
{
    public function __construct(
        public readonly ?string $conversationId,
        public readonly string $text = '',
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $toolCallCount = 0,
        public readonly int $durationMs = 0,
        public readonly bool $succeeded = true,
        public readonly ?string $errorMessage = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
    ) {}
}
