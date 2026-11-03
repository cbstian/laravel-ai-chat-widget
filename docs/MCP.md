# Tools MCP en el chat

Guía para incorporar o actualizar el catálogo MCP de `cbstian/laravel-ai-chat-widget` en una app Laravel. El flujo general del paquete sigue en [INTEGRATION.md](INTEGRATION.md).

El catálogo está **apagado por defecto**. Un `composer update` no cambia el chat si no tocas config ni `app/Ai/ChatAgent.php`.

## Qué hace

Con `AI_CHAT_MCP=true`, el widget puede ofrecer al agente tools de **esta misma app** que implementan `Laravel\Mcp\Server\Tool`.

- Se ejecutan **en el mismo request** de Livewire. No hay otra petición HTTP ni un proceso `artisan`.
- `laravel/ai` las envuelve con `Laravel\Ai\Tools\McpServerTool`.
- `$request->user()` es la persona autenticada del chat. Las policies y `Gate` que miran a ese usuario siguen valiendo.
- En esta versión solo entran tools de **solo lectura**: clase marcada con `#[IsReadOnly]` (`readOnlyHint === true`).
- La lista es explícita. Vacía significa ninguna tool, aunque exista un servidor MCP.

## Qué no hace

- No llama a servidores MCP de otra máquina ni de la propia app por HTTP (`Client::web`, `Mcp::web`).
- No arranca `php artisan mcp:start` ni usa `Client::local`.
- No publica rutas MCP. Si la app ya las tiene para clientes externos, el chat no las usa.
- No expone tools de escritura ni tools sin `#[IsReadOnly]`. Esas se omiten y queda una línea `debug` en el log. La confirmación en el widget (fase M2) todavía no existe: `mcp.confirm_writes` está reservado y **no** las enciende.
- No inyecta recursos ni prompts MCP en el agente (fase M3).
- No aplana un grupo `ToolSearch`. Esas tools no salen solas; hay que nombrar la clase en `mcp.tools`.
- No añade métodos a `ChatDriver`. Un driver de chat propio no tiene que implementarlo.
- No migra tablas.

## Requisitos

- El paquete ya exige `laravel/ai` ^1.0. Ese rango envuelve `Laravel\Mcp\Server\Tool` en proceso.
- `laravel/mcp` **no** se instala con el widget. Hace falta pedirlo en la app:

```bash
composer require laravel/mcp:^1.1
```

Sin ese paquete, o si `AI_CHAT_MCP` no es `true`, el catálogo devuelve un array vacío y el chat sigue igual.

PHP 8.3+, Laravel 12 o 13 y Livewire 4 son los del paquete. Hace falta un usuario autenticado con id numérico, igual que el resto del chat.

## Instalación nueva

1. Instala el widget como en [INTEGRATION.md](INTEGRATION.md) (`ai-chat:install`, config y migraciones de `laravel/ai`).
2. Cuando quieras MCP:

```bash
composer require laravel/mcp:^1.1
```

3. El `ChatAgent` que genera `ai-chat:install` ya incluye el spread del catálogo. Con el flag apagado no añade tools. No hace falta `ai-chat:install --force` si el agente ya existe: ese flag pisa `app/Ai`.
4. Si publicaste `config/ai-chat.php`, copia el bloque `mcp` de la sección [Actualizar una app que ya está en producción](#actualizar-una-app-que-ya-está-en-producción). Si no lo publicaste, el del paquete alcanza y `AI_CHAT_MCP` lo enciende.
5. Crea la tool (ejemplo más abajo), pon su clase en `mcp.tools` y deja `AI_CHAT_MCP=false` hasta probarla.

```env
AI_CHAT_MCP=false
```

## Actualizar una app que ya está en producción

1. `composer update cbstian/laravel-ai-chat-widget`. Sin `migrate`. El chat sigue con las mismas tools.
2. Cuando quieras MCP, en **esa** app: `composer require laravel/mcp:^1.1`. El `suggest` del paquete no lo instala solo.
3. Si **no** publicaste `config/ai-chat.php`, no copies nada: la clave nueva se lee con default `false`. Pasa a `AI_CHAT_MCP=true` solo cuando la allowlist esté lista.
4. Si **sí** publicaste `config/ai-chat.php` antes de esta versión, ese archivo reemplaza al del paquete y no trae `mcp`. Hasta que copies el bloque, `config('ai-chat.mcp.enabled', false)` deja el catálogo apagado. Añade esto al array publicado (no hace falta borrar el resto):

```php
'mcp' => [
    'enabled' => filter_var(env('AI_CHAT_MCP', false), FILTER_VALIDATE_BOOLEAN),
    // class-string de Laravel\Mcp\Server\Tool. Vacío = ninguna.
    'tools' => [
        // \App\Mcp\Tools\CurrentUserTool::class,
    ],
    // class-string de Laravel\Mcp\Server de esta misma app. Mismos filtros que tools.
    'servers' => [
        // \App\Mcp\Servers\AppServer::class,
    ],
    // Nombres MCP (kebab), no el basename PHP.
    'except' => [],
    // Reservado para la confirmación en el widget. Hoy no expone tools de escritura.
    'confirm_writes' => true,
],
```

`mergeConfigFrom` no rellena un config ya publicado. Una clave `mcp` definida en un service provider reemplaza el array entero.

5. `ai-chat:install` **no** reescribe `app/Ai/ChatAgent.php`. Hay que añadir el spread a mano.

Antes:

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

Después:

```php
public function tools(): iterable
{
    return [
        new \Cbstian\AiChat\Tools\ListMarkdownDocuments,
        new \Cbstian\AiChat\Tools\ReadMarkdownDocument,
        new \App\Ai\Tools\BuscarFactura,
        ...app(\Cbstian\AiChat\Mcp\McpToolCatalog::class)->tools(),
    ];
}
```

El `use` correspondiente:

```php
use Cbstian\AiChat\Mcp\McpToolCatalog;
```

Con `enabled` en false el spread añade cero tools. Las tools de negocio que ya estaban no se tocan.

6. `.env`:

```env
AI_CHAT_MCP=false
```

7. `php artisan config:clear` si la config está cacheada. No hay assets nuevos ni vista que publicar solo por MCP.

## Variables de entorno

| Variable | Default | Efecto |
|---|---|---|
| `AI_CHAT_MCP` | `false` | `true` permite el catálogo. Sigue vacío si `tools` y `servers` no listan nada que pase los filtros |

No hay env para la lista de clases: va en `config/ai-chat.php` (o en el config publicado). No pegues nombres de clase solo en `.env`.

## Escribir una tool compatible

Tiene que extender `Laravel\Mcp\Server\Tool`, llevar `#[IsReadOnly]` y comprobar al usuario dentro de `handle()` o `shouldRegister()`. El catálogo no relaja esa comprobación.

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CurrentUserTool extends Tool
{
    protected string $name = 'current-user';

    protected string $description = 'Devuelve el nombre de la persona autenticada. No modifica datos.';

    public function shouldRegister(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('view-own-profile');
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->can('view-own-profile')) {
            return Response::error('No autorizado.');
        }

        $name = data_get($user, 'name');

        return Response::text(is_string($name) && $name !== '' ? $name : 'sin nombre');
    }
}
```

- `#[IsReadOnly]` pone `readOnlyHint` en true. Sin esa anotación la tool se trata como de escritura y **no** se entrega al modelo.
- `shouldRegister()` es opcional. Si existe y devuelve false, la tool no sale. El contenedor la llama, así que puede usar `auth()` y `Gate`.
- `$request` es `Laravel\Mcp\Request`. `user()` usa el resolver de `auth` del request HTTP actual (el de Livewire).
- El nombre que ve el modelo es `$name` o, si lo omites, el kebab del basename (`CurrentUserTool` → `current-user-tool`). No es el nombre de la clase PHP.
- La etiqueta mientras streamea sale de `ai-chat.tool_labels` con **esa** clave kebab. Si no está, se usa `default_tool_label` (`Consultando…`).

```php
'tool_labels' => [
    'ListMarkdownDocuments' => 'Buscando documentos…',
    'ReadMarkdownDocument' => 'Leyendo documentación…',
    'current-user' => 'Leyendo tu usuario…',
],
```

## Registrar tools

### Allowlist `mcp.tools`

Lista de clases. Vacía = ninguna, aunque el servidor MCP de la app tenga muchas.

```php
'tools' => [
    \App\Mcp\Tools\CurrentUserTool::class,
],
```

Una clase que no extiende `Laravel\Mcp\Server\Tool` se ignora.

### Servidor de la misma app `mcp.servers`

Opcional. La clase extiende `Laravel\Mcp\Server` y declara sus tools en `$tools`. El catálogo la instancia en proceso, sin transporte de red, y lee `createContext()->tools()`. Después aplica los mismos filtros (solo lectura, `shouldRegister`, `except`).

```php
namespace App\Mcp\Servers;

use App\Mcp\Tools\CurrentUserTool;
use Laravel\Mcp\Server;

class AppServer extends Server
{
    protected array $tools = [
        CurrentUserTool::class,
    ];
}
```

```php
'servers' => [
    \App\Mcp\Servers\AppServer::class,
],
```

No registres `Mcp::web` ni `Mcp::local` para que el chat las vea. Esas rutas sirven a clientes externos. El chat no las llama.

Si la misma tool está en `tools` y en `servers`, entra una sola vez (gana el primer nombre MCP).

Un grupo armado con `ToolSearch` no se convierte en una lista plana. Nombra cada tool en `mcp.tools` si el chat debe verla.

### Excluir por nombre `mcp.except`

Nombres MCP, no clases:

```php
'except' => ['current-user'],
```

### Extender el catálogo

En el `boot` de un service provider, con los mismos filtros:

```php
app(\Cbstian\AiChat\Mcp\McpToolCatalog::class)->extend(
    fn (): array => [\App\Mcp\Tools\CurrentUserTool::class],
);
```

## Apagarlo en un widget

La config manda. La pantalla solo puede forzar el apagado:

```blade
@aiChatStyles
@livewire('ai-chat-widget', ['mcp' => false])
```

`mcp => true` no enciende la función si `AI_CHAT_MCP` es false. La prop está bloqueada: el navegador no puede pasarla de false a true.

**Filament.** `AI_CHAT_FILAMENT_HOOK=true` monta el widget sin argumentos, así que hereda la config. Para apagar MCP en un panel y dejarlo en otro, no uses el hook global: monta el componente a mano en el render hook, con `['mcp' => false]` donde no corresponda. No combines el hook automático y un montaje manual: salen dos chats.

## Verificación

1. `composer show laravel/mcp` muestra ^1.1 en la app (no basta con que este paquete lo sugiera).
2. Con `AI_CHAT_MCP=false`, un mensaje no llama tools MCP nuevas. El chat se ve igual que antes del update.
3. `config('ai-chat.mcp.enabled')` es `false` si el `.env` no tiene la variable o si el config publicado no tiene el bloque.
4. Con `AI_CHAT_MCP=true`, `tools` apuntando a una clase `#[IsReadOnly]` y el spread en `ChatAgent::tools()`, el modelo puede llamar esa tool.
5. Durante el stream, el rótulo usa la clave kebab de `tool_labels` (o «Consultando…»).
6. Una tool sin `#[IsReadOnly]` no aparece. En el log, con nivel `debug`, hay una línea que nombra la tool y dice que no es de solo lectura.
7. `shouldRegister(): false` la deja fuera sin ese mensaje de escritura.
8. `@livewire('ai-chat-widget', ['mcp' => false])` no entrega tools MCP aunque la config esté en true.
9. `$request->user()` dentro de `handle()` es el usuario del chat, no `null`, en un request autenticado.

En el workbench del paquete (`composer serve`), `AI_CHAT_MCP=true` hace que la respuesta local diga `Tools MCP de este turno: current-user`. Ese agente es un fake: nombra las tools y no le pide a un modelo que las ejecute. Con el flag en false dice `ninguna`.

## Si la tool no aparece

| Síntoma | Qué mirar |
|---|---|
| El chat no cambió tras el update | Esperable. Falta `AI_CHAT_MCP=true`, el bloque en el config publicado, o el spread en `ChatAgent` |
| `config('ai-chat.mcp.enabled')` sigue false | Config cacheada: `php artisan config:clear`. O el archivo publicado no tiene `mcp` |
| No hay error y la lista está vacía | `laravel/mcp` no está instalado en la app. `composer require laravel/mcp:^1.1` |
| La clase está en `tools` y no sale | Sin `#[IsReadOnly]`, o `IsReadOnly(false)`. Busca en el log `debug` el nombre de la tool |
| `shouldRegister` devuelve false | La tool se omite. Revisa el usuario y el `Gate` en ese método |
| Está dentro de un `ToolSearch` | No se aplana. Añade la clase a `mcp.tools` |
| El nombre en `except` coincide | `except` usa el nombre MCP (`current-user`), no `CurrentUserTool` |
| El rótulo del stream dice «Consultando…» | `tool_labels` no tiene la clave kebab |
| El widget de una pantalla no las usa y el resto sí | Esa pantalla pasa `['mcp' => false]` |
| Quieres llamar al `Mcp::web` de la misma app | No es este camino. En `php artisan serve` o con pocos workers PHP puede bloquearse, y la sesión no viaja sola |

## Seguridad

- Allowlist vacía no expone nada. No hay modo «todas las tools del servidor».
- El gate del chat no sustituye la policy de la tool. `handle()` y `shouldRegister()` tienen que mirar `$request->user()`.
- Una tool de escritura anotada como `#[IsReadOnly]` se entregaría igual: la anotación es el contrato que el catálogo puede ver. No marques como solo lectura algo que escribe.
- Hasta la fase de confirmación, lo que no es solo lectura se omite. No se ejecuta «de paso».
- `verbose_logs` sigue guardando los argumentos de cada tool que el modelo llama, en `ai_chat_tool_call_logs`. Conectar MCP puede meter más datos ahí. No subas el log de argumentos si la tool recibe datos sensibles.
- No leas recursos MCP y los pegues en `instructions()`. Pueden ser grandes o de otro usuario.

## Límites y lo que viene

- **Ahora:** allowlist, servidores de la misma app en proceso, solo lectura, opt-out por widget.
- **M2 (no está):** el widget muestra una pausa y la persona aprueba o rechaza tools que escriben. Hasta entonces `confirm_writes` no cambia el comportamiento.
- **M3 (no está):** recursos y prompts MCP como tools opt-in. No entran solos al prompt del sistema.
