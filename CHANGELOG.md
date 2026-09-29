# Changelog

## Unreleased

### Changed
- Requiere `laravel/ai` ^1.0. Los logs de tokens leen `inputTokens` y `outputTokens`. El historial del widget solo muestra turnos `completed`.

### Added
- Tools `ListMarkdownDocuments` y `ReadMarkdownDocument` para que el LLM lea Markdown de directorios configurados.
- `ai-chat:install` genera el agente, el factory y el gate de acceso en la app.
- Guía `docs/INTEGRATION.md`, guideline y skill de Laravel Boost para agentes que integran el paquete.

### Fixed
- Con `AI_CHAT_PERSIST=false`, abrir el chat ya no consulta `ai_chat_sessions`.
- El hook de Filament incluye `@aiChatStyles`.
- El HTML crudo del welcome y de las respuestas se escapa al renderizar Markdown.
- `ai-chat.agent`, `ai-chat.access` y `ai-chat.driver` tienen que implementar su contrato.

### Changed
- Namespace PHP: `PazCiudadana\AiChat` → `Cbstian\AiChat`.

### Changed
- Identidad pública: `cbstian/laravel-ai-chat-widget`.
- README, MIT (© Sebastian Aguilera) y VERSIONING.md (SemVer).

### Added
- Suite Pest con gateway fake de laravel/ai (`FakeChatAgent::fake`).
- Tests de acceso, driver, widget Livewire, config e install.
- Skeleton oficial Laravel como `cbstian/laravel-ai-chat-widget`.
- Widget Livewire flotante (FAB, expand/collapse, streaming, welcome MD).
- Contratos ResolvesChatAgent / ResolvesChatAccess / ChatDriver.
- AgentChatDriver sobre laravel/ai.
- Migraciones opcionales de sesiones y logs verbosos.
- CSS `.pc-ai-chat` y comando `ai-chat:install`.
- README + docs/BITACORA.md.
