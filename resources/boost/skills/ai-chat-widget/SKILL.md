---
name: ai-chat-widget
description: "Install and configure cbstian/laravel-ai-chat-widget. Use when adding the floating Livewire AI chat, wiring laravel/ai, the agent factory, the access gate, Markdown document tools, the Filament hook, or AI_CHAT_* env vars."
---

# laravel-ai-chat-widget

Úsalo al detectar, instalar, configurar o implementar `cbstian/laravel-ai-chat-widget` en una app Laravel. No reimplementes el widget, el driver ni las tools de Markdown.

## Lecturas

Lee estos archivos del paquete (en `vendor/cbstian/laravel-ai-chat-widget/` o el path que Composer haya instalado) antes de editar la app:

- `docs/INTEGRATION.md` — flujo completo y tabla de config
- `README.md`
- `config/ai-chat.php`
- `resources/stubs/welcome.md`
- `resources/stubs/agent/ChatAgent.php.stub`
- `resources/stubs/agent/ChatAgentFactory.php.stub`
- `resources/stubs/agent/ChatAccessGate.php.stub`

Sigue `docs/INTEGRATION.md`. Las reglas de abajo son las que más se saltan.

## Detectar

- Composer: `cbstian/laravel-ai-chat-widget`
- Namespace: `Cbstian\AiChat`
- Provider con auto-discovery: `Cbstian\AiChat\AiChatServiceProvider`
- Componente: `ai-chat-widget`
- Directiva: `@aiChatStyles`
- Contratos de la app: `ResolvesChatAgent`, `ResolvesChatAccess`

Si no está en Packagist, añade el repositorio VCS `https://github.com/cbstian/laravel-ai-chat-widget` y requiere `dev-master`, o un repositorio `path` local.

## Instalar

```bash
composer require cbstian/laravel-ai-chat-widget
php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

El install genera `app/Ai/ChatAgent.php`, `ChatAgentFactory.php` y `ChatAccessGate.php` con el namespace raíz de la app. No los pisa si ya existen. No escribe `.env`.

Sin las migraciones de `laravel/ai` (`agent_conversations`) el panel no recupera el historial. El paquete requiere `laravel/ai` ^1.0. Si la app ya migró 0.x, corre el backfill de `steps` y `status` de su guía de upgrade antes de desplegar.

## Configurar

```env
AI_CHAT_AGENT=App\Ai\ChatAgentFactory
AI_CHAT_ACCESS=App\Ai\ChatAccessGate
AI_CHAT_PROVIDER=openai
AI_CHAT_MODEL=gpt-4o-mini
OPENAI_API_KEY=
```

Ajusta el namespace y el provider. La API key es de `laravel/ai` (`config/ai.php`), no de este paquete. `AI_CHAT_PROVIDER` vacío cae en `config('ai.default')`.

Documentos que el LLM puede leer: archivos `.md` en `resources/ai/chat` (ahí queda `welcome.md`) y directorios extra en `AI_CHAT_DOCUMENT_PATHS` (separados por comas, absolutos o relativos a la raíz del proyecto).

## Implementar

- Deja el `ChatAgent` generado. Ya incluye `ListMarkdownDocuments` y `ReadMarkdownDocument`, `Promptable` y `RemembersConversations`.
- Tools de dominio: `php artisan make:tool Nombre` y añádelas en `tools()` junto a las dos del paquete. El nombre público es el basename de la clase. Etiqueta el streaming en `ai-chat.tool_labels`.
- El factory recibe `($user, $context)`. El contexto entra por `@livewire('ai-chat-widget', ['context' => [...]])`.
- Blade: `@aiChatStyles` y `@livewire('ai-chat-widget')` una vez por layout.
- Filament: `AI_CHAT_FILAMENT_HOOK=true` **o** el montaje manual, no los dos.

## Comprobaciones

- Invitado: no ve el chat. El gate no puede autorizar invitados.
- El id de usuario es numérico.
- `AI_CHAT_PERSIST=false` no elimina la memoria de `laravel/ai`.
- `fab_icon` no cambia el botón.
- Un mensaje real usa el provider configurado. Una pregunta sobre `welcome.md` debe llamar a las tools de Markdown.
