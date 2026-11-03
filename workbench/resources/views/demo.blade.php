<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Chat Workbench</title>
    @aiChatStyles
    <style>
        body {
            margin: 0;
            background: #f3f4f6;
            color: #111827;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }

        main {
            max-width: 40rem;
            margin: 4rem auto;
            padding: 0 1.5rem;
        }

        h1 {
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
        }

        p {
            line-height: 1.5;
            color: #374151;
        }
    </style>
</head>
<body>
    <main>
        <h1>Workbench — laravel-ai-chat-widget</h1>
        <p>Sesión de prueba: {{ auth()->user()?->email }}.</p>
        <p>Abre el chat con el botón flotante y envía un mensaje. La respuesta la genera un agente local, sin proveedor real.</p>
        <p>Con <code>AI_CHAT_MCP=true</code> la respuesta nombra la tool de solo lectura <code>current-user</code>. El agente local no le pide al modelo que la ejecute.</p>
        <p>El micrófono sale porque este workbench enciende <code>voice.enabled</code>. El texto queda en el campo para revisarlo antes de enviar.</p>
        <p>Driver que está usando el servidor: <strong>{{ config('ai-chat.voice.driver', 'browser') }}</strong>.
            @if (config('ai-chat.voice.driver') === 'browser')
                Modo del widget: Web Speech. No llama a xAI aunque <code>XAI_API_KEY</code> tenga valor.
            @else
                Modo del widget: sube el audio y lo transcribe en el servidor.
            @endif
            Si cambiaste <code>workbench/.env</code> y esto no coincide, vuelve a correr <code>composer serve</code>.
        </p>
    </main>

    @livewire('ai-chat-widget')
</body>
</html>
