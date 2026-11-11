<?php

declare(strict_types=1);

namespace Cbstian\AiChat\History;

use BadMethodCallException;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\StoredMessage;
use Throwable;

/**
 * Decorador del store de laravel/ai. Compacta solo la lectura hacia el proveedor.
 *
 * También implementa las interfaces extra del store de base, porque el SDK
 * hace instanceof sobre el binding (paginación, approvals, dueño).
 */
final class CompactingConversationStore implements ConversationStore, PaginatesConversations, ResolvesPendingApprovals, VerifiesConversationOwnership
{
    public function __construct(
        private readonly ConversationStore $inner,
        private readonly ToolResultCompactor $compactor,
    ) {}

    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return $this->inner->latestConversationId($participantType, $participantId, $agent);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $this->inner->storeConversation($participantType, $participantId, $title, $id);
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        return $this->inner->storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        return $this->inner->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->compactor->compact(
            $conversationId,
            $this->inner->getLatestConversationMessages($conversationId, $limit),
        );
    }

    /**
     * @param  array<int, ToolResult>  $toolResults
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        $this->inner->storeApprovalResults($conversationId, $toolResults);
    }

    /**
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        if (! $this->inner instanceof PaginatesConversations) {
            throw new BadMethodCallException('El store de conversaciones no pagina mensajes.');
        }

        return $this->inner->paginateConversationMessages($conversationId, $perPage, $cursorName, $cursor);
    }

    public function pendingApprovalsFor(string $conversationId): array
    {
        if (! $this->inner instanceof ResolvesPendingApprovals) {
            throw new BadMethodCallException('El store de conversaciones no resuelve approvals.');
        }

        return $this->inner->pendingApprovalsFor($conversationId);
    }

    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        if (! $this->inner instanceof VerifiesConversationOwnership) {
            throw new BadMethodCallException('El store de conversaciones no verifica al dueño.');
        }

        return $this->inner->conversationBelongsTo($conversationId, $participantType, $participantId);
    }
}
