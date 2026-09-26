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
    </main>

    @livewire('ai-chat-widget')
</body>
</html>
