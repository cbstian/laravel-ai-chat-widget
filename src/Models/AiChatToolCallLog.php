<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatToolCallLog extends Model
{
    protected $table = 'ai_chat_tool_call_logs';

    protected $fillable = [
        'ai_chat_turn_log_id',
        'ai_chat_session_id',
        'tool_name',
        'arguments_json',
        'result_preview',
        'duration_ms',
    ];

    public function turnLog(): BelongsTo
    {
        return $this->belongsTo(AiChatTurnLog::class, 'ai_chat_turn_log_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'ai_chat_session_id');
    }
}
