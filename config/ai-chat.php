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

    // Clave = nombre de la tool. Las de este paquete usan el basename PHP.
    // Una tool MCP usa su nombre kebab (current-user), no el basename de la clase.
    'tool_labels' => [
        'ListMarkdownDocuments' => 'Buscando documentos…',
        'ReadMarkdownDocument' => 'Leyendo documentación…',
        // 'current-user' => 'Leyendo tu usuario…',
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

    // Tools MCP de la propia app, in-process. Apagado por defecto.
    // Un config publicado viejo no tiene esta clave: enabled se lee con default false.
    'mcp' => [
        'enabled' => filter_var(env('AI_CHAT_MCP', false), FILTER_VALIDATE_BOOLEAN),
        // class-string de Laravel\Mcp\Server\Tool. Vacío = ninguna.
        'tools' => [],
        // class-string de Laravel\Mcp\Server de esta app. Sus tools pasan por los mismos filtros.
        'servers' => [],
        // Nombres MCP (kebab) a excluir aunque la clase esté listada.
        'except' => [],
        // Reservado para M2. En esta versión las tools de escritura se omiten, no se confirman.
        'confirm_writes' => true,
    ],

    // Dictado. enabled false gana siempre: sin esta clave (config publicada vieja) el micrófono no aparece.
    // La API key de xAI no se renderiza. Pégala en .env, no en un config commiteado.
    'voice' => [
        'enabled' => filter_var(env('AI_CHAT_VOICE', false), FILTER_VALIDATE_BOOLEAN),
        'driver' => env('AI_CHAT_VOICE_DRIVER', 'browser'),
        // BCP 47. browser lo usa tal cual. laravel-ai y xai lo mapean.
        'language' => env('AI_CHAT_VOICE_LANGUAGE', 'es-CL'),
        'auto_submit' => filter_var(env('AI_CHAT_VOICE_AUTO_SUBMIT', false), FILTER_VALIDATE_BOOLEAN),
        // null: sin filtro. Lista de ids de panel Filament. Fuera de Filament no aplica.
        'panels' => null,

        'max_seconds' => (int) env('AI_CHAT_VOICE_MAX_SECONDS', 60),
        'max_kilobytes' => (int) env('AI_CHAT_VOICE_MAX_KB', 2048),
        'rate_limit_per_minute' => (int) env('AI_CHAT_VOICE_RATE_LIMIT', 10),

        'browser' => [
            'interim_results' => true,
            'continuous' => false,
        ],

        'laravel-ai' => [
            // Vacío: solo usa ai.default si ese provider transcribe. xAI y Anthropic no sirven aquí.
            'provider' => env('AI_CHAT_VOICE_PROVIDER'),
            'model' => env('AI_CHAT_VOICE_MODEL'),
            'diarize' => false,
        ],

        'xai' => [
            'key' => env('XAI_API_KEY'),
            'url' => env('AI_CHAT_XAI_STT_URL', 'https://api.x.ai/v1/stt'),
            'model' => env('AI_CHAT_XAI_STT_MODEL', 'grok-voice-transcribe-2.0'),
            'format' => filter_var(env('AI_CHAT_XAI_STT_FORMAT', true), FILTER_VALIDATE_BOOLEAN),
            'filler_words' => false,
            'keyterms' => [],
            'timeout' => 30,
        ],
    ],
];
