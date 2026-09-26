<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $agent_conversation_id
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiChatSession extends Model
{
    protected $table = 'ai_chat_sessions';

    protected $fillable = [
        'user_id',
        'agent_conversation_id',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * @return HasMany<AiChatTurnLog, $this>
     */
    public function turnLogs(): HasMany
    {
        return $this->hasMany(AiChatTurnLog::class);
    }
}
