<?php

declare(strict_types=1);

return [
    'enabled' => env('AI_CHAT_ENABLED', true),
    'persist' => env('AI_CHAT_PERSIST', true),
    'verbose_logs' => env('AI_CHAT_VERBOSE_LOGS', true),

    'title' => env('AI_CHAT_TITLE', 'Asistente IA'),
    'subtitle' => env('AI_CHAT_SUBTITLE', 'Soporte y análisis potenciados con IA'),

    'welcome_markdown' => resource_path('ai/chat/welcome.md'),
    'welcome_markdown_inline' => <<<'MD'
Hola, soy tu asistente. Puedo ayudarte con lo que este proyecto haya conectado vía tools.

Escribe tu pregunta para comenzar.
MD,

    'fab_icon' => 'chat',
    'fab_icon_svg' => null,

    'colors' => [
        'primary' => '#4A4D46',
        'header' => '#4A4D46',
        'header_text' => '#ffffff',
        'user_bubble' => '#4A4D46',
        'assistant_bubble' => '#ffffff',
        'fab' => '#4A4D46',
        'panel_bg' => '#f9fafb',
    ],

    'provider' => env('AI_CHAT_PROVIDER'),
    'model' => env('AI_CHAT_MODEL'),

    'agent' => env('AI_CHAT_AGENT'),
    'access' => env('AI_CHAT_ACCESS'),
    'driver' => null,

    'rate_limit' => [
        'per_minute' => (int) env('AI_CHAT_RATE_LIMIT', 20),
    ],

    'max_prompt_length' => (int) env('AI_CHAT_MAX_PROMPT_LENGTH', 8000),

    'tool_labels' => [],
    'default_tool_label' => 'Consultando…',

    'filament' => [
        'register_render_hook' => env('AI_CHAT_FILAMENT_HOOK', false),
    ],
];
