<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_tool_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_chat_turn_log_id')->nullable()->constrained('ai_chat_turn_logs')->nullOnDelete();
            $table->foreignId('ai_chat_session_id')->nullable()->constrained('ai_chat_sessions')->cascadeOnDelete();
            $table->string('tool_name');
            $table->text('arguments_json')->nullable();
            $table->text('result_preview')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_tool_call_logs');
    }
};
