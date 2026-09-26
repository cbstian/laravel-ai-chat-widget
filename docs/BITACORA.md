# Bitácora — laravel-ai-chat

Fecha: 2026-09-18  
Ubicación: `/home/cbstian/www/laravel-ai-chat`

## Decisiones

1. **Skeleton oficial Laravel** (`laravel package`) en lugar de Spatie.
2. Vendor namespace **un solo segmento**: `PazCiudadana` (el configure rechaza namespaces con `\`).
3. Paquete = **UI + config + persistencia opcional**. Agent/tools/RAG quedan en cada app.
4. Streaming con `laravel/ai` ^0.11 (API de `datos.pazciudadana.local`).
5. CSS encapsulado `.pc-ai-chat` + variables `--pc-ai-*`.

## Scaffold

```bash
cd /home/cbstian/www
laravel package laravel-ai-chat --no-interaction \
  --config --views --migrations --assets --commands \
  --author-name="Sebastian Aguilera" \
  --package-name="cbstian/laravel-ai-chat-widget" \
  --vendor-namespace="PazCiudadana" \
  --class-name="AiChat"
```

Primer intento falló por `--vendor-namespace="Cbstian\\AiChat"`.  
Se corrigió con `php configure.php -n --vendor-namespace=PazCiudadana`.

## Implementación v1

Contratos, `AgentChatDriver`, Livewire `AiChatWidget`, modelos/migraciones, CSS, `ai-chat:install`, README y esta bitácora.

## Referencias

- `datos.pazciudadana.local`: SupportChat + ChatService + DatosChatAgent
- `voxlitycs`: ChatSupport + tools vectoriales (fuera del paquete)

## Pendientes

- Tests Pest con fake gateway
- Piloto en datos.pazciudadana.local
- Alinear voxlitycs a laravel/ai ^0.11

## Integración para agentes (2026-09-26)

- `docs/INTEGRATION.md` es el flujo que debe seguir un agente en la app anfitriona.
- Boost: `resources/boost/guidelines/core.blade.php` y skill `ai-chat-widget`.
- `ai-chat:install` genera `ChatAgent`, `ChatAgentFactory` y `ChatAccessGate`.
- Tools del paquete: `ListMarkdownDocuments` y `ReadMarkdownDocument`.
- `AI_CHAT_PERSIST=false` ya no consulta `ai_chat_sessions` al abrir el panel.
- El hook de Filament incluye `@aiChatStyles`.
- Markdown del welcome y de las respuestas escapa HTML crudo.

## Tests Pest + gateway fake (2026-09-18)

- TestCase con Testbench + `AiServiceProvider` + Livewire + sqlite in-memory.
- Fixtures: `FakeChatAgent` (Promptable + RemembersConversations), factory, Allow/Deny access, User.
- Feature:
  - access/rate/empty prompt
  - `FakeChatAgent::fake([...])` streaming + turn logs + persist on/off
  - Config + `ai-chat:install`
  - Livewire widget (submit + ask; buffer de stream)
- Arch: sin dd/exit; strict types.
- `beStrictAboutOutputDuringTests=false` por el output de Livewire `stream()`.

Resultado: `vendor/bin/pest` → **19 passed**.

## Docs OSS (2026-09-20)

- Repo: https://github.com/cbstian/laravel-ai-chat-widget
- README + LICENSE.md MIT + VERSIONING.md
- composer.name → `cbstian/laravel-ai-chat-widget` (namespace PHP `Cbstian\AiChat` pendiente)

## Rename namespace (2026-09-20)

- PHP: `PazCiudadana\AiChat` → `Cbstian\AiChat`
- Tests: `Cbstian\AiChat\Tests`
- Provider Laravel: `Cbstian\AiChat\AiChatServiceProvider`
- Composer ya era `cbstian/laravel-ai-chat-widget`
- Pest: 19 passed
- CSS `.pc-ai-chat` se mantiene (prefijo de clases, no vendor PHP)

