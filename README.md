# laravel-ai-chat-widget

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20)](https://laravel.com/)

Widget de **chat IA flotante** para **Laravel** + **Livewire 4**.

Sirve la UI (FAB, ventana, colores, título, welcome en Markdown, CSS encapsulado) y, si quieres, persistencia detallada de conversaciones. **El agente, las tools, el RAG y los datos viven en tu app** mediante contratos.

- Repo: [github.com/cbstian/laravel-ai-chat-widget](https://github.com/cbstian/laravel-ai-chat-widget)
- Licencia: [MIT](LICENSE.md)
- Versionado: [VERSIONING.md](VERSIONING.md) (SemVer)

> **Estado:** pre-`1.0` (`0.x`). La API pública puede ajustar entre minors; ver política de versionado.

## Requisitos

- PHP 8.3+
- Laravel 12 o 13
- Livewire 4
- [`laravel/ai`](https://github.com/laravel/ai) `^0.11`

## Instalación

Cuando esté en Packagist:

```bash
composer require cbstian/laravel-ai-chat-widget
php artisan ai-chat:install
php artisan migrate
```

Mientras tanto, desde VCS:

```bash
composer config repositories.laravel-ai-chat-widget vcs https://github.com/cbstian/laravel-ai-chat-widget
composer require cbstian/laravel-ai-chat-widget:dev-main
php artisan ai-chat:install
php artisan migrate
```

O con path local:

```bash
composer config repositories.laravel-ai-chat-widget path ../laravel-ai-chat
composer require cbstian/laravel-ai-chat-widget:@dev
```

## Configuración mínima

Tras publicar config (`config/ai-chat.php`):

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
```

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

El objeto devuelto debe ser un agente `laravel/ai` usable con streaming (p. ej. `Promptable` + conversaciones).

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

- Manual: render hook `BODY_END` con `@livewire('ai-chat-widget')`
- O `AI_CHAT_FILAMENT_HOOK=true` para auto-registro

## Qué hace / qué no hace

| Incluye | No incluye |
|---------|------------|
| Widget flotante Livewire | Definición del agente de dominio |
| Colores, título, welcome `.md` | Tools / RAG / vectores |
| CSS encapsulado (`.pc-ai-chat`) | Providers de IA (usa `laravel/ai` de la app) |
| Persistencia opcional de sesiones y logs | Autorización de negocio (salvo el contrato de acceso) |

## Persistencia

Tablas opcionales (MySQL / PostgreSQL):

- `ai_chat_sessions`
- `ai_chat_turn_logs`
- `ai_chat_tool_call_logs`

Desactiva con `AI_CHAT_PERSIST=false` / `AI_CHAT_VERBOSE_LOGS=false`.

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
