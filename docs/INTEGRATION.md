# Integrar laravel-ai-chat-widget

Guía para un agente o un desarrollador que incorpora el paquete en **otra** app Laravel. El paquete sirve la UI, la config visual, la persistencia opcional y dos tools para leer Markdown. El agente de dominio, las tools de negocio y las API keys viven en la app, conectados con [`laravel/ai`](https://github.com/laravel/ai).

## Lecturas antes de editar la app

Lee estos archivos del paquete instalado (`vendor/cbstian/laravel-ai-chat-widget/` o el path de Composer) antes de generar código:

| Archivo | Para qué |
|---|---|
| `README.md` | Contrato público corto |
| `docs/INTEGRATION.md` | Este flujo |
| `config/ai-chat.php` | Todas las claves, con el efecto de cada una |
| `resources/stubs/welcome.md` | Mensaje inicial que publica `ai-chat:install` |
| `resources/stubs/agent/ChatAgent.php.stub` | Agente que ya conecta las tools de Markdown |
| `resources/stubs/agent/ChatAgentFactory.php.stub` | Implementación de `ResolvesChatAgent` |
| `resources/stubs/agent/ChatAccessGate.php.stub` | Implementación de `ResolvesChatAccess` |

Con Laravel Boost, `php artisan boost:install` incorpora la guideline del paquete y el skill `ai-chat-widget`. Sigue ese skill y este documento; no reimplementes el widget.

## Qué detectar

| Señal | Valor |
|---|---|
| Composer | `cbstian/laravel-ai-chat-widget` |
| Namespace PHP | `Cbstian\AiChat` |
| Provider | `Cbstian\AiChat\AiChatServiceProvider` (auto-discovery) |
| Config | `config/ai-chat.php`, clave `ai-chat` |
| Comando | `php artisan ai-chat:install` |
| Componente Livewire | `ai-chat-widget` |
| Directiva de estilos | `@aiChatStyles` |
| Contratos | `ResolvesChatAgent`, `ResolvesChatAccess`, `ChatDriver` |

No está en Packagist todavía. Hasta entonces, el `composer.json` de la app necesita el repositorio VCS o un `path`.

## Requisitos

- PHP 8.3+
- Laravel 12 o 13
- Livewire 4
- `laravel/ai` ^1.0 (dependencia del paquete; igual hay que publicar su config y migrar). Si la app ya tenía las tablas de 0.x, corre el backfill de [UPGRADE.md](https://github.com/laravel/ai/blob/1.x/UPGRADE.md) (`steps` y `status`) antes de desplegar. Una instalación nueva publica el esquema 1.0 al hacer `vendor:publish` del provider de `laravel/ai`.
- Usuario autenticado con **id numérico**. Invitados no entran. Un `user_id` UUID rompe `ai_chat_sessions` y también `agent_conversations` de `laravel/ai` (`participant_id` es entero)

## Instalación

```bash
composer config repositories.laravel-ai-chat-widget vcs https://github.com/cbstian/laravel-ai-chat-widget
composer require cbstian/laravel-ai-chat-widget:dev-master

php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

Path local durante el desarrollo del paquete:

```bash
composer config repositories.laravel-ai-chat-widget path ../laravel-ai-chat
composer require cbstian/laravel-ai-chat-widget:@dev
```

### Actualizar (VCS)

`composer install` no mueve `dev-master`. En la app:

```bash
composer update cbstian/laravel-ai-chat-widget
```

Si el commit no cambia, borra `vendor/cbstian/laravel-ai-chat-widget`, corre `composer clear-cache` y vuelve a actualizar con `--prefer-source`. Publica de nuevo migraciones/assets si el paquete los cambió; no uses `ai-chat:install --force` si no quieres pisar `app/Ai`. Con `path`, `git pull` en el clone basta.

Detalle en el [README](../README.md#actualizar-vcs).

`ai-chat:install` publica:

| Tag | Destino |
|---|---|
| `ai-chat-config` | `config/ai-chat.php` |
| `ai-chat-migrations` | migraciones `ai_chat_*` |
| `ai-chat-assets` | `public/vendor/ai-chat` |
| `ai-chat-stubs` | `resources/ai/chat/welcome.md` |
| (el comando, no un tag) | `app/Ai/ChatAgent.php`, `ChatAgentFactory.php`, `ChatAccessGate.php` |

No sobrescribe clases de `app/Ai` que ya existan. `ai-chat:install --force` las reemplaza, igual que el resto de archivos publicados. El comando **no** edita `.env`.

`vendor:publish` de `Laravel\Ai\AiServiceProvider` crea `config/ai.php` y la migración de `agent_conversations` / `agent_conversation_messages`. Sin esa migración el historial del LLM no se guarda y el panel no puede recargar mensajes.

## Variables de entorno

Añade esto y ajusta el namespace si la app no usa `App\`:

```env
AI_CHAT_ENABLED=true
AI_CHAT_TITLE="Asistente IA"
AI_CHAT_SUBTITLE="Soporte y análisis potenciados con IA"
AI_CHAT_PROVIDER=openai
AI_CHAT_MODEL=gpt-4o-mini
AI_CHAT_AGENT=App\Ai\ChatAgentFactory
AI_CHAT_ACCESS=App\Ai\ChatAccessGate
AI_CHAT_PERSIST=true
AI_CHAT_VERBOSE_LOGS=true
AI_CHAT_RATE_LIMIT=20
AI_CHAT_MAX_PROMPT_LENGTH=8000
AI_CHAT_FILAMENT_HOOK=false
AI_CHAT_DOCUMENT_PATHS=
AI_CHAT_DOCUMENTS_MAX_BYTES=200000
AI_CHAT_DOCUMENTS_MAX_FILES=200

OPENAI_API_KEY=
```

`OPENAI_API_KEY` la lee `config/ai.php` de `laravel/ai`, no este paquete. Otros providers: `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `MISTRAL_API_KEY`, `OLLAMA_URL`, etc. El nombre del provider (`openai`, `anthropic`, `ollama`, …) va en `AI_CHAT_PROVIDER` y debe existir en `config/ai.php`.

Si `AI_CHAT_PROVIDER` está vacío, el driver usa `config('ai.default')` (openai en la config publicada de `laravel/ai`). Si `AI_CHAT_MODEL` está vacío, `laravel/ai` elige el modelo por defecto de ese provider.

Después de cambiar config ya cacheada: `php artisan config:clear`.

## Configuración

Cada clave está comentada en `config/ai-chat.php`. Resumen operativo:

| Clave | Env | Efecto |
|---|---|---|
| `enabled` | `AI_CHAT_ENABLED` | `false` oculta el chat para todos |
| `persist` | `AI_CHAT_PERSIST` | Tablas `ai_chat_sessions`, `ai_chat_turn_logs`, `ai_chat_tool_call_logs`. No desactiva la memoria de `laravel/ai` |
| `verbose_logs` | `AI_CHAT_VERBOSE_LOGS` | Escribe logs de turno y de tool calls. Si `persist` es `false`, no hay sesión y los logs no se guardan |
| `title`, `subtitle` | `AI_CHAT_TITLE`, `AI_CHAT_SUBTITLE` | Cabecera del panel |
| `welcome_markdown` | — | `resources/ai/chat/welcome.md`. Si no existe, se usa `welcome_markdown_inline` |
| `colors.*` | — | Variables CSS `--pc-ai-*` |
| `fab_icon`, `fab_icon_svg` | — | Reservadas. Hoy el botón muestra 💬 y estas claves no cambian la UI |
| `provider`, `model` | `AI_CHAT_PROVIDER`, `AI_CHAT_MODEL` | Se pasan a `stream()` del agente |
| `agent` | `AI_CHAT_AGENT` | Obligatoria. Debe implementar `ResolvesChatAgent`. Si la clase no existe o no implementa el contrato, el contenedor lanza `RuntimeException` |
| `access` | `AI_CHAT_ACCESS` | Opcional. Vacío o clase inexistente = cualquier usuario autenticado. Si la clase existe y no implementa `ResolvesChatAccess`, lanza `RuntimeException` |
| `driver` | — | Opcional. Vacío = `AgentChatDriver`. Si se define, debe implementar `ChatDriver` |
| `rate_limit.per_minute` | `AI_CHAT_RATE_LIMIT` | Por id de usuario, ventana de 60 segundos. El intento cuenta al empezar, también si el provider falla |
| `max_prompt_length` | `AI_CHAT_MAX_PROMPT_LENGTH` | Validación del widget y del driver |
| `tool_labels` | — | Mapa nombre de tool → texto mientras streamea. Por defecto cubre `ListMarkdownDocuments` y `ReadMarkdownDocument` |
| `default_tool_label` | — | `Consultando…` si el nombre no está en el mapa |
| `filament.register_render_hook` | `AI_CHAT_FILAMENT_HOOK` | `true` inyecta estilos y widget al final del body de Filament |
| `documents.paths` | `AI_CHAT_DOCUMENT_PATHS` | Directorios legibles por las tools. Siempre incluye `resources/ai/chat`. El env añade rutas separadas por comas, absolutas o relativas a la raíz del proyecto |
| `documents.max_bytes` | `AI_CHAT_DOCUMENTS_MAX_BYTES` | Tope al leer un archivo |
| `documents.max_files` | `AI_CHAT_DOCUMENTS_MAX_FILES` | Tope del listado |

`mergeConfigFrom` no fusiona arrays anidados. Si publicas `config/ai-chat.php`, edita ese archivo: una clave `documents` en un service provider reemplaza el array entero.

## Agente y tools del LLM

El driver no llama al provider por su cuenta. Resuelve `ai-chat.agent`, llama `make($user, $context)` y espera un objeto con `stream()`. Para conversaciones de más de un turno el agente tiene que usar el trait `RemembersConversations` de `laravel/ai` (`continue()` y `forUser()`). El timeout del stream es 120 segundos.

`ai-chat:install` genera este cableado:

- `ChatAgent` implementa `Agent`, `Conversational`, `HasTools`, usa `Promptable` y `RemembersConversations`, y registra `ListMarkdownDocuments` y `ReadMarkdownDocument`
- `ChatAgentFactory` implementa `ResolvesChatAgent`
- `ChatAccessGate` implementa `ResolvesChatAccess`

Esas dos tools son del paquete (`Cbstian\AiChat\Tools`). No leen `.env`, no salen de `documents.paths`, no siguen symlinks hacia fuera del directorio y solo abren `.md` / `.markdown`. La ruta que entiende `ReadMarkdownDocument` es la que imprime el listado, con el basename del directorio como prefijo (`chat/welcome.md`).

Para documentación extra, copia `.md` dentro de `resources/ai/chat` o añade directorios:

```env
AI_CHAT_DOCUMENT_PATHS=docs,storage/app/guides
```

Las instrucciones del agente publicado le piden usar esas tools antes de inventar el contenido de un documento.

### Tool de dominio

Las tools de negocio se escriben en la app (`php artisan make:tool Nombre`) y se devuelven junto a las de Markdown:

```php
public function tools(): iterable
{
    return [
        new \Cbstian\AiChat\Tools\ListMarkdownDocuments,
        new \Cbstian\AiChat\Tools\ReadMarkdownDocument,
        new \App\Ai\Tools\BuscarFactura,
    ];
}
```

Una tool de `laravel/ai` implementa `Laravel\Ai\Contracts\Tool`: `description()`, `schema(JsonSchema $schema)` y `handle(Request $request)`. El nombre que ve el modelo y `tool_labels` es el basename de la clase (`BuscarFactura`). Añade la etiqueta en `config/ai-chat.php`:

```php
'tool_labels' => [
    'ListMarkdownDocuments' => 'Buscando documentos…',
    'ReadMarkdownDocument' => 'Leyendo documentación…',
    'BuscarFactura' => 'Buscando la factura…',
],
```

El factory recibe el usuario y el `context` del widget. Úsalos para acotar tools; el agente publicado los ignora hasta que la app los pase al constructor.

```blade
@livewire('ai-chat-widget', ['context' => ['area' => 'facturas']])
```

## Montaje

El chat solo se renderiza si hay un usuario autenticado y `canAccess()` es true. Hace falta Livewire y, una sola vez por layout, el CSS encapsulado en `.pc-ai-chat`.

**Blade**

```blade
@aiChatStyles
@livewire('ai-chat-widget')
```

**Filament**

- `AI_CHAT_FILAMENT_HOOK=true` registra `PanelsRenderHook::BODY_END` con los estilos y el widget. No montes el componente otra vez en el panel: saldrían dos chats.
- Manual, con el flag en `false`: el mismo par de directivas en un render hook `BODY_END`.

El HTML del welcome y de las respuestas pasa por Markdown con el HTML crudo escapado y sin links `javascript:`. El stream en vivo también escapa el texto. No hace falta sanitizar otra vez en la vista.

## Persistencia

Hay dos capas:

1. **`laravel/ai`**: `agent_conversations` y `agent_conversation_messages`. Es el historial que el widget vuelve a pintar. Hace falta aunque `AI_CHAT_PERSIST=false`. Solo entran turnos con `status = completed`; un turno `failed` o `paused` no se muestra. `prompt_tokens` y `completion_tokens` de `ai_chat_turn_logs` copian `inputTokens` y `outputTokens` (totales del provider, caché y reasoning incluidos).
2. **Este paquete**, si `AI_CHAT_PERSIST=true`:
   - `ai_chat_sessions` (`user_id` entero, `agent_conversation_id`, `context` JSON)
   - `ai_chat_turn_logs` (provider, modelo, tokens, duración, longitudes, éxito, error)
   - `ai_chat_tool_call_logs` (nombre y argumentos). El `ai_chat_turn_log_id` queda `null`: el log de la tool se escribe durante el stream y el del turno después, y no se enlazan.

Con `AI_CHAT_PERSIST=false` el driver no crea sesiones ni consulta `ai_chat_sessions` al abrir el panel. El id de conversación sigue viviendo en el estado de Livewire y en `laravel/ai`.

## Restricciones que conviene no redescubrir

- Invitados: `canAccess(null)` es false siempre. El gate de acceso no puede abrir el chat al público.
- Id de usuario numérico, alineado con `laravel/ai`.
- El agente tiene que exponer `stream()`. Sin `RemembersConversations` cada mensaje es una conversación nueva.
- `AI_CHAT_PROVIDER` / `AI_CHAT_MODEL` los aplica el driver al llamar a `stream()`. No hace falta repetirlos en atributos del agente, salvo que quieras otro criterio.
- Timeout fijo de 120 segundos en el driver.
- `fab_icon` y `fab_icon_svg` no tienen efecto.
- Activar el hook de Filament y además poner `@livewire('ai-chat-widget')` duplica el widget.
- Los logs verbosos dependen de `persist`.
- Una tool de dominio que devuelva HTML acaba escapada en la burbuja, porque la respuesta del modelo se renderiza como Markdown seguro.

## Verificación en la app

1. `php artisan ai-chat:install` termina en `Laravel AI Chat instalado.` y existen `app/Ai/ChatAgentFactory.php` y `resources/ai/chat/welcome.md`.
2. `php artisan migrate` crea `ai_chat_*` y `agent_conversations`.
3. `.env` tiene `AI_CHAT_AGENT`, `AI_CHAT_ACCESS` y la API key del provider.
4. Con un usuario autenticado, el layout muestra el botón. Un invitado no ve el panel.
5. Un mensaje de prueba vuelve del provider configurado. Si falla, el texto de la excepción queda en el panel y, con persistencia verbosa, en `ai_chat_turn_logs.error_message`.
6. Preguntar por el contenido de `welcome.md` debe provocar las tools `ListMarkdownDocuments` y `ReadMarkdownDocument` (el rótulo «Buscando documentos…» / «Leyendo documentación…» durante el stream).
7. Con `AI_CHAT_FILAMENT_HOOK=true`, el panel de Filament muestra el chat con estilos y sin un segundo montaje manual.

## Qué no meter en el paquete

RAG, vectores, permisos de negocio y tools que leen la base de datos de la app se quedan en la app. El paquete solo ofrece el contrato, la UI y la lectura de Markdown acotada a los directorios configurados.
