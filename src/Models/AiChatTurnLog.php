<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiChatTurnLog extends Model
{
    protected $table = 'ai_chat_turn_logs';

    protected $fillable = [
        'ai_chat_session_id',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'duration_ms',
        'prompt_length',
        'response_length',
        'tool_call_count',
        'succeeded',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AiChatSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'ai_chat_session_id');
    }

    /**
     * @return HasMany<AiChatToolCallLog, $this>
     */
    public function toolCallLogs(): HasMany
    {
        return $this->hasMany(AiChatToolCallLog::class);
    }
}
