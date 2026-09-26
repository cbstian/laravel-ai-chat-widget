<?php

declare(strict_types=1);

$extraDocumentPaths = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('AI_CHAT_DOCUMENT_PATHS', '')),
)));

return [
    // Interruptor del widget. Con false, canAccess() es false para todos.
    'enabled' => env('AI_CHAT_ENABLED', true),

    // Tablas ai_chat_* de este paquete. No apaga el historial de laravel/ai.
    'persist' => env('AI_CHAT_PERSIST', true),

    // Logs de turnos y tool calls. Solo se escriben si persist es true.
    'verbose_logs' => env('AI_CHAT_VERBOSE_LOGS', true),

    'title' => env('AI_CHAT_TITLE', 'Asistente IA'),
    'subtitle' => env('AI_CHAT_SUBTITLE', 'Soporte y análisis potenciados con IA'),

    // Si el archivo no existe, el widget usa welcome_markdown_inline.
    'welcome_markdown' => resource_path('ai/chat/welcome.md'),
    'welcome_markdown_inline' => <<<'MD'
Hola, soy tu asistente. Puedo ayudarte con lo que este proyecto haya conectado vía tools.

Escribe tu pregunta para comenzar.
MD,

    // Reservados. El FAB renderiza el emoji fijo 💬; estas claves aún no cambian la UI.
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

    // Vacío: el driver usa config('ai.default') de laravel/ai (openai por defecto).
    'provider' => env('AI_CHAT_PROVIDER'),

    // Vacío: laravel/ai elige el modelo por defecto de ese provider.
    'model' => env('AI_CHAT_MODEL'),

    // Clase que implementa Cbstian\AiChat\Contracts\ResolvesChatAgent. Obligatoria.
    'agent' => env('AI_CHAT_AGENT'),

    // Clase que implementa ResolvesChatAccess. Vacío = cualquier usuario autenticado.
    // Los invitados siempre se rechazan, aunque esta clase devuelva true.
    'access' => env('AI_CHAT_ACCESS'),

    // Clase que implementa ChatDriver. Vacío = Cbstian\AiChat\Drivers\AgentChatDriver.
    'driver' => null,

    'rate_limit' => [
        'per_minute' => (int) env('AI_CHAT_RATE_LIMIT', 20),
    ],

    'max_prompt_length' => (int) env('AI_CHAT_MAX_PROMPT_LENGTH', 8000),

    // Clave = nombre de la tool (basename de la clase). Valor = texto mientras streamea.
    'tool_labels' => [
        'ListMarkdownDocuments' => 'Buscando documentos…',
        'ReadMarkdownDocument' => 'Leyendo documentación…',
    ],
    'default_tool_label' => 'Consultando…',

    'filament' => [
        // true registra BODY_END con @aiChatStyles y el widget. No lo combines con un montaje manual.
        'register_render_hook' => env('AI_CHAT_FILAMENT_HOOK', false),
    ],

    // Directorios que ListMarkdownDocuments / ReadMarkdownDocument pueden leer.
    // paths: resources/ai/chat más rutas absolutas o relativas a base_path() en AI_CHAT_DOCUMENT_PATHS.
    'documents' => [
        'paths' => array_merge(
            [resource_path('ai/chat')],
            $extraDocumentPaths,
        ),
        'max_bytes' => (int) env('AI_CHAT_DOCUMENTS_MAX_BYTES', 200_000),
        'max_files' => (int) env('AI_CHAT_DOCUMENTS_MAX_FILES', 200),
    ],
];
