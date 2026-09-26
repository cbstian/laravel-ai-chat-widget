<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Contracts;

use Cbstian\AiChat\Dto\ChatTurnResult;
use Illuminate\Contracts\Auth\Authenticatable;

interface ChatDriver
{
    public function canAccess(?Authenticatable $user): bool;

    /**
     * @param  array<string, mixed>  $context
     * @param  callable(object): void  $onEvent
     */
    public function ask(
        Authenticatable $user,
        string $prompt,
        ?string $conversationId,
        array $context,
        callable $onEvent,
    ): ChatTurnResult;

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messages(Authenticatable $user, ?string $conversationId, int $limit = 50): array;

    /**
     * @param  array<string, mixed>  $context
     */
    public function startNew(Authenticatable $user, array $context = []): ?string;
}
