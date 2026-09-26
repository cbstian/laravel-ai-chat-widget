@verbatim
## laravel-ai-chat-widget

Widget de chat IA flotante (Livewire 4). La UI, la config visual y la persistencia opcional vienen del paquete. El agente de dominio se conecta con laravel/ai. No reimplementes el widget ni el driver.

Antes de instalar o cablear el paquete, lee `vendor/cbstian/laravel-ai-chat-widget/docs/INTEGRATION.md` y el skill `ai-chat-widget`. También `config/ai-chat.php` y `resources/stubs/agent/*.stub` del mismo paquete.

### Instalación mínima

```bash
php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

`ai-chat:install` publica config, migraciones `ai_chat_*`, `resources/ai/chat/welcome.md` y genera `app/Ai/ChatAgent.php`, `ChatAgentFactory.php` y `ChatAccessGate.php` (no pisa clases existentes; `--force` sí). No edita `.env`.

### Obligatorio en .env

```env
AI_CHAT_AGENT=App\Ai\ChatAgentFactory
AI_CHAT_ACCESS=App\Ai\ChatAccessGate
AI_CHAT_PROVIDER=openai
AI_CHAT_MODEL=gpt-4o-mini
OPENAI_API_KEY=
```

`OPENAI_API_KEY` pertenece a `config/ai.php` de laravel/ai. Si `AI_CHAT_PROVIDER` está vacío se usa `config('ai.default')`.

### Montaje

Blade, una vez por layout:

```blade
@aiChatStyles
@livewire('ai-chat-widget')
```

Filament: `AI_CHAT_FILAMENT_HOOK=true` inyecta estilos y widget en `BODY_END`. No montes el componente otra vez.

### Tools

El agente generado ya registra `Cbstian\AiChat\Tools\ListMarkdownDocuments` y `ReadMarkdownDocument`. Leen solo `.md` / `.markdown` bajo `resources/ai/chat` y los directorios de `AI_CHAT_DOCUMENT_PATHS`. Las tools de negocio se añaden en `tools()` del agente de la app (`php artisan make:tool`). El nombre de la tool es el basename de la clase; las etiquetas de streaming van en `ai-chat.tool_labels`.

### No romper

- Hace falta un usuario autenticado con id numérico. Los invitados no entran.
- `AI_CHAT_PERSIST=false` solo apaga las tablas `ai_chat_*`. El historial del chat sigue en las tablas de laravel/ai.
- El agente debe usar `RemembersConversations` y exponer `stream()`.
- `fab_icon` y `fab_icon_svg` no cambian la UI.
@endverbatim
