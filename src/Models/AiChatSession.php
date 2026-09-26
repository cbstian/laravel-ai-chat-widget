<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function turnLogs(): HasMany
    {
        return $this->hasMany(AiChatTurnLog::class);
    }
}
