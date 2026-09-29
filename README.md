# laravel-ai-chat-widget

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)](https://laravel.com/)

Widget de **chat IA flotante** para **Laravel** + **Livewire 4**.

Sirve la UI (FAB, ventana, colores, título, welcome en Markdown, CSS encapsulado), persistencia opcional de conversaciones y dos tools para que el LLM lea Markdown de directorios configurados. **El agente de dominio, el resto de tools, el RAG y los datos viven en tu app** mediante contratos de [`laravel/ai`](https://github.com/laravel/ai).

Para instalarlo en otra app, un agente debe seguir [docs/INTEGRATION.md](docs/INTEGRATION.md). Con Laravel Boost, `php artisan boost:install` carga la guideline del paquete y el skill `ai-chat-widget`.

- Repo: [github.com/cbstian/laravel-ai-chat-widget](https://github.com/cbstian/laravel-ai-chat-widget)
- Licencia: [MIT](LICENSE.md)
- Versionado: [VERSIONING.md](VERSIONING.md) (SemVer)

> **Estado:** pre-`1.0` (`0.x`). La API pública puede ajustar entre minors; ver política de versionado.

## Requisitos

- PHP 8.3+
- Laravel 12 o 13
- Livewire 4
- [`laravel/ai`](https://github.com/laravel/ai) `^1.0`

## Instalación

Cuando esté en Packagist:

```bash
composer require cbstian/laravel-ai-chat-widget
php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

Mientras tanto, desde VCS:

```bash
composer config repositories.laravel-ai-chat-widget vcs https://github.com/cbstian/laravel-ai-chat-widget
composer require cbstian/laravel-ai-chat-widget:dev-master
php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

O con path local:

```bash
composer config repositories.laravel-ai-chat-widget path ../laravel-ai-chat
composer require cbstian/laravel-ai-chat-widget:@dev
php artisan ai-chat:install
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

## Configuración mínima

`ai-chat:install` crea `app/Ai/ChatAgent.php`, `ChatAgentFactory.php` y `ChatAccessGate.php`, y publica `resources/ai/chat/welcome.md`. No modifica `.env`. La tabla completa de claves está en [docs/INTEGRATION.md](docs/INTEGRATION.md) y comentada en `config/ai-chat.php`.

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
OPENAI_API_KEY=
```

`OPENAI_API_KEY` la consume `laravel/ai` (`config/ai.php`). Si `AI_CHAT_PROVIDER` queda vacío, se usa `config('ai.default')`. El agente generado ya registra `ListMarkdownDocuments` y `ReadMarkdownDocument`, que leen `.md` de `resources/ai/chat` y de los directorios en `AI_CHAT_DOCUMENT_PATHS`.

### Contrato del agente

```php
namespace App\Ai;

use Illuminate\Contracts\Auth\Authenticatable;
use Cbstian\AiChat\Contracts\ResolvesChatAgent;

class ChatAgentFactory implements ResolvesChatAgent
{
    public function make(?Authenticatable $user, array $context = []): object
    {
        return MyDomainAgent::make(/* tools / scopes de tu app */);
    }
}
```

El objeto devuelto debe exponer `stream()` y usar `RemembersConversations` para continuar la conversación. `ai-chat:install` ya genera `ChatAgent` con eso y con las tools de Markdown.

> Namespace PHP: `Cbstian\AiChat` (Composer: `cbstian/laravel-ai-chat-widget`).

### Acceso (opcional)

```php
use Cbstian\AiChat\Contracts\ResolvesChatAccess;

class ChatAccessGate implements ResolvesChatAccess
{
    public function canAccess(?Authenticatable $user): bool
    {
        return $user !== null; // o tu política
    }
}
```

### Montaje

**Blade / Livewire**

```blade
@aiChatStyles
@livewire('ai-chat-widget')
```

**Filament**

- `AI_CHAT_FILAMENT_HOOK=true` registra `BODY_END` con `@aiChatStyles` y el widget
- Manual, con el flag en `false`: las dos directivas en ese hook

No actives el hook y además montes el componente: el chat saldría duplicado. Hace falta un usuario autenticado con id numérico; los invitados no ven el panel.

## Qué hace / qué no hace

| Incluye | No incluye |
|---------|------------|
| Widget flotante Livewire | El agente de dominio (hay stub en `ai-chat:install`) |
| Colores, título, welcome `.md` | Tools de negocio, RAG, vectores |
| CSS encapsulado (`.pc-ai-chat`) | API keys (van en `laravel/ai`) |
| Persistencia opcional de sesiones y logs | Autorización de negocio (salvo el contrato de acceso) |
| Tools `ListMarkdownDocuments` y `ReadMarkdownDocument` | Lectura de archivos fuera de `ai-chat.documents.paths` |

## Persistencia

Dos capas. Las tablas de `laravel/ai` (`agent_conversations`, `agent_conversation_messages`) guardan el historial que el widget vuelve a mostrar. Las de este paquete son opcionales:

- `ai_chat_sessions`
- `ai_chat_turn_logs`
- `ai_chat_tool_call_logs`

`AI_CHAT_PERSIST=false` apaga solo las tablas `ai_chat_*`. `AI_CHAT_VERBOSE_LOGS=false` apaga los logs de turno y de tools; si no hay persistencia, esos logs tampoco se escriben.

## Tests

```bash
vendor/bin/pest
```

Incluye gateway fake de `laravel/ai` para no pegarle a providers reales.

## Versionado y changelog

- Política: [VERSIONING.md](VERSIONING.md)
- Cambios: [CHANGELOG.md](CHANGELOG.md)

## Contribuir

Issues y PRs en el [repositorio](https://github.com/cbstian/laravel-ai-chat-widget). Mantén Pest en verde y documenta breaking changes.

## Licencia

[MIT](LICENSE.md) © Sebastian Aguilera
