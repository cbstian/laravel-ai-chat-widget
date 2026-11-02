# Plan: dictado por voz e integración con Laravel MCP

Estado: **análisis y plan**. No hay código de estas funciones en el paquete.
Fecha de la investigación: 2026-11-02.
Paquete: `cbstian/laravel-ai-chat-widget` (pre-`1.0`, instalaciones de producción vía VCS).

Este documento no es API pública. La política de versionado trata `docs/` como interno salvo que un release lo indique.

## Recomendación corta

1. **Voz.** Camino principal: Web Speech API en el navegador (`SpeechRecognition` / `webkitSpeechRecognition`), idioma `es-CL`, desactivado por defecto. El texto dictado se escribe en el textarea para que la persona lo revise y pulse enviar. Fallback posterior, también opt-in: grabar con `MediaRecorder` y transcribir en el servidor con `Laravel\Ai\Transcription`. No hace falta un proveedor nuevo fuera de `laravel/ai`.
2. **MCP.** No llamar al servidor MCP de la propia app por HTTP ni por `mcp:start`. `laravel/ai` ^1.0 ya envuelve las clases `Laravel\Mcp\Server\Tool` in-process (`McpServerTool`) y también las tools de un cliente MCP. El paquete debe ofrecer un catálogo opt-in, con allowlist vacía, y `laravel/mcp` solo como `suggest`. La app sigue registrando las tools en `ChatAgent::tools()`. La confirmación humana de tools que escriben es una fase aparte: el widget hoy no sabe reanudar un turno en pausa.

Las dos funciones caben en un **MINOR** (`0.x` o, más adelante, `1.x`): claves de config nuevas con default apagado, sin migración, sin método nuevo obligatorio en `ChatDriver`.

---

## 1. Arquitectura actual que estas features tocan

El paquete sirve UI, acceso, streaming y dos tools de Markdown. El agente de dominio vive en la app.

```text
@livewire('ai-chat-widget')
        │
        ▼
AiChatWidget  ──ask()──►  ChatDriver (AgentChatDriver)
        │                      │
        │                      ├─ ResolvesChatAccess
        │                      ├─ ResolvesChatAgent::make($user, $context)
        │                      └─ $agent->stream($prompt)   // laravel/ai
        │
        └─ vista Blade + Alpine mínimo + CSS .pc-ai-chat
```

| Pieza | Dónde | Qué importa para voz / MCP |
| --- | --- | --- |
| Componente | `src/Livewire/AiChatWidget.php` | Estado: `prompt`, `pendingQuestion`, `streamingAnswer`, `messages`, `conversationId` (`#[Locked]`), `context` (`#[Locked]`). `submit()` valida y llama `ask()`. `ask()` solo reacciona a `ToolCall`, `StreamStart` y `TextDelta`. |
| Vista | `resources/views/livewire/widget.blade.php` | Textarea `wire:model="prompt"`, Enter envía, botón enviar. Alpine solo hace scroll y el atajo de teclado. No hay asset JS propio ni Vite. |
| CSS | `resources/css/ai-chat.css` | Se incrusta con `@aiChatStyles`. El formulario es `.pc-ai-chat__form` (flex). Un botón de micrófono entra ahí. |
| Driver | `src/Drivers/AgentChatDriver.php` | Resuelve el agente, aplica provider/model, timeout 120 s, rate limit, y persiste. No inyecta tools. El historial visible filtra `MessageStatus::Completed`. Un turno `paused` o `failed` no se pinta. |
| Contratos | `src/Contracts/` | `ChatDriver`, `ResolvesChatAgent`, `ResolvesChatAccess`. Son API pública. Añadir un método a `ChatDriver` rompe implementaciones de las apps. |
| Agente publicado | `resources/stubs/agent/ChatAgent.php.stub` | `HasTools` + `Promptable` + `RemembersConversations`. `tools()` devuelve `ListMarkdownDocuments` y `ReadMarkdownDocument`. Las apps de producción ya tienen su copia en `app/Ai` y `ai-chat:install` no la pisa. |
| Factory | `ChatAgentFactory.php.stub` | Recibe `$user` y `$context`. Hoy ignora ambos. |
| Tools del paquete | `src/Tools/` | Implementan `Laravel\Ai\Contracts\Tool`. El nombre que ve el modelo y `tool_labels` es el basename de la clase. |
| Config | `config/ai-chat.php` | `mergeConfigFrom`. Si la app publicó el archivo, ese archivo **reemplaza** el del paquete: las claves nuevas no aparecen solas. El código tiene que usar defaults en el `config()` / `data_get`. |
| Filament | `AiChatServiceProvider::registerFilamentHook()` | Un solo hook global `BODY_END` si `AI_CHAT_FILAMENT_HOOK=true`. No hay config por panel. |
| Persistencia | migraciones `ai_chat_*` | El id de usuario es entero. No hace falta tabla nueva para ninguna de las dos features en la primera entrega. |
| Tests | Pest + Testbench, gateway fake | `tests/Feature/AiChatWidgetTest.php`, `AgentChatDriverFakeGatewayTest.php`, `ConfigTest.php`. No hay suite de navegador. |

El `context` del widget viaja al factory. No controla la UI. Un override de voz por pantalla sería otra prop de `mount()`, no una clave dentro de `context`.

---

## 2. Dictado por voz

### 2.1 Opciones

| | Web Speech API | Grabar y transcribir con `laravel/ai` |
| --- | --- | --- |
| Dónde corre | Navegador. Chrome/Edge mandan el audio al servicio del vendor (Google o Microsoft). Safari usa el motor de Apple. | El navegador graba (`MediaRecorder`) y Livewire sube el archivo. `Transcription::fromUpload(...)->generate()` lo manda al provider de `config/ai.php` (OpenAI, compatible, Groq, etc.). |
| Soporte (nov 2026) | Chrome, Edge, Safari 14.1+ / iOS 14.5+ (prefijo `webkit`). Firefox de escritorio y Android: no está habilitado. Chromium embebido (Electron) suele fallar con error `network` porque no tiene el servicio de Google. En iPhone todos los navegadores usan WebKit, así que sí hay reconocimiento. | Cualquier navegador que grabe `audio/webm`, `audio/ogg`, `audio/mp4` o `audio/wav`. El gateway OpenAI de `laravel/ai` ya mapea esos MIME. |
| `es-CL` | `recognition.lang = 'es-CL'` (BCP 47). Chrome lo lista desde hace años. La calidad depende del servicio del vendor, no del paquete. Si no se fija `lang`, gana el `lang` del HTML o el del navegador. | El idioma del SDK es ISO 639-1 (`es`), no `es-CL`. Whisper y los modelos `gpt-4o-*-transcribe` no distinguen el acento chileno con un tag regional. Los tests del SDK exponen `language` en el prompt de transcripción; el método público del builder hay que confirmarlo en el minor instalado antes de codificar. |
| Privacidad | El audio **no** pasa por Laravel, pero **sí** sale del dispositivo hacia Google, Microsoft o Apple. Hay que decirlo en la UI. Hace falta HTTPS (o localhost), permiso de micrófono y un gesto del usuario (`start()` desde el click). | El audio sale hacia el provider de IA que la app ya configuró. No se debe persistir el archivo. Encaja mejor si la política interna prohíbe Google pero ya acepta OpenAI (u otro provider de `laravel/ai`). |
| Costo y latencia | Sin costo de API y el texto llega mientras se habla (`interimResults`). | Un request por toma, tokens de audio, y la persona espera el upload. Diarización no aporta en un chat de una sola voz. |
| Offline | Chrome reciente tiene `processLocally`, `available()` e `install()`, con pocos idiomas. No depender de eso en la v1. | No. |
| Encaje con el paquete | Solo Blade, Alpine y CSS. El `prompt` sigue siendo el de siempre. Cero cambios de contrato y cero migraciones. | `WithFileUploads` en el componente, límites de tamaño y duración, rate limit propio, borrado del temporal, tests con `Transcription::fake()`. |

### 2.2 Recomendación

**Primero el navegador. El servidor queda como fallback opt-in, no como camino por defecto.**

Motivos:

- El gesto del usuario es dictar una frase y corregirla. Meter el audio en el mismo provider del chat añade costo, latencia y una superficie de upload que hoy no existe.
- Las apps en producción no tienen que configurar un modelo de transcripción para probar la función.
- Firefox y los Chromium sin servicio de voz son la razón del fallback, no el caso principal. Si en las apps reales Firefox es residual, la fase de servidor puede esperar.

El fallback no es un segundo motor en paralelo. Con `browser_with_server_fallback` el navegador se usa si `SpeechRecognition` existe; si no, se muestra el botón de grabación. Nunca se mandan los dos.

### 2.3 Niveles de activación

Hay tres niveles distintos. Conviene implementar los tres, con esta precedencia: **el servidor manda; el cliente solo puede apagar**.

| Nivel | Qué es | Default | Notas |
| --- | --- | --- | --- |
| Paquete / app | `ai-chat.voice.enabled` y `AI_CHAT_VOICE`. Cada app tiene su config: este flag **es** el interruptor por aplicación. | `false` | Una app que publicó `config/ai-chat.php` no recibe la clave sola. Leer con default `false`. |
| Panel Filament | `voice.panels`: `null` (todos los paneles, si la voz está on) o lista de ids (`admin`, `cliente`). Fuera de Filament no aplica. | `null` | Hoy el hook es global. El widget puede leer `Filament::getCurrentPanel()?->getId()` solo si la clase existe. No hace falta un hook por panel. |
| Pantalla | Argumento de mount: `@livewire('ai-chat-widget', ['voice' => false])`. | hereda la config | Sirve para una vista concreta. No va dentro de `context`, porque `context` es del agente. |
| Persona | Toggle en el header del panel, recordado en `localStorage` (`pc-ai-chat-voice`). | visible si la voz está on; `voice.user_can_disable` default `true` | Solo oculta el botón. No puede encender una voz que la config apagó, ni elegir el driver servidor. Sin columna nueva y sin migración. |

No guardar el toggle en base de datos en la primera entrega. Un ajuste por usuario sería otra tabla o una preferencia de la app, y no hace falta para probar la función.

### 2.4 Comportamiento de producto

- El micrófono escribe en el textarea. **No envía solo.** Así la persona corrige nombres propios y el rate limit no se gasta con falsos positivos.
- Una sola toma (`continuous: false`) e interinos en el textarea (`interimResults: true`). Al soltar o al detectar `onspeechend`, el texto queda y el foco vuelve al input.
- Si el navegador no tiene la API, el botón no se renderiza (fase 1) o cambia al modo grabación (fase 2).
- Texto de ayuda corto, visible al activar: el reconocimiento del navegador lo procesa el proveedor del navegador, no esta aplicación.
- El límite `max_prompt_length` (8000) sigue aplicando al enviar. Si el dictado se pasa, se recorta en el cliente y `submit()` ya valida.

### 2.5 Fases de voz

#### Fase V1 — Web Speech, opt-in

Config propuesta (defaults seguros):

```php
'voice' => [
    'enabled' => env('AI_CHAT_VOICE', false),
    'locale' => env('AI_CHAT_VOICE_LOCALE', 'es-CL'),
    'driver' => 'browser', // browser | server | browser_with_server_fallback
    'user_can_disable' => true,
    'panels' => null, // null o lista de ids de panel Filament
],
```

En V1, `driver` distinto de `browser` se trata como `browser` si el servidor aún no existe, o se ignora el botón si se pide `server` antes de V2. Mejor: V1 solo acepta `browser`; un valor desconocido no enciende nada.

Archivos:

- `config/ai-chat.php` — bloque `voice`.
- `resources/views/livewire/widget.blade.php` — botón junto a enviar, Alpine en el formulario. Sin archivo JS compilado: el paquete no tiene bundler y la vista ya usa Alpine.
- `resources/css/ai-chat.css` — estado escuchando (pulso), botón deshabilitado, foco visible.
- `src/Livewire/AiChatWidget.php` — en `render()`, pasar `voiceEnabled` ya resuelto (config + panel + prop de mount). La prop de mount queda `#[Locked]` para que el cliente no la encienda.
- `docs/INTEGRATION.md`, guideline de Boost y skill `ai-chat-widget` — una fila de config y la advertencia de privacidad. El stub del agente no cambia.
- `tests/Feature/AiChatWidgetTest.php` y `ConfigTest.php` — ver abajo.

Sin migración, sin contrato nuevo, sin cambio de `ask()`.

Tests Pest:

- Con el default, el HTML del widget no contiene el botón de micrófono ni `SpeechRecognition`.
- Con `voice.enabled=true` y un usuario autorizado, el botón está y el `lang` es `es-CL`.
- Con `voice.panels` que no incluye el panel actual (Filament falso o id inyectado), el botón no está.
- Con `voice` en `false` en el mount, el botón no está aunque la config esté on.
- Un invitado sigue sin ver el panel.
- `submit()` / `ask()` no cambian: el texto dictado es un `prompt` normal.

La prueba de micrófono real queda en el workbench (`composer serve`), a mano, en Chrome y Safari. Pest no tiene navegador.

#### Fase V2 — fallback de servidor

Solo si V1 se queda corto (Firefox, o una app que no puede mandar audio al vendor del navegador).

- `driver`: `server` o `browser_with_server_fallback`.
- Acción Livewire `transcribe`, con `WithFileUploads`, que rechaza el archivo si el driver no es de servidor.
- `Transcription::fromUpload($this->audio)->generate()` (y el idioma ISO `es` derivado del locale). Provider y modelo vacíos usan los defaults de `config/ai.php`. Claves opcionales: `voice.transcription_provider`, `voice.transcription_model`.
- Topes: duración ~60 s, tamaño ~2 MB, MIME allowlist, rate limit distinto (`ai-chat:voice:{id}`, más estricto que los 20 mensajes/minuto). Borrar el temporal aunque la transcripción falle. No escribir el binario en `ai_chat_turn_logs`.
- El texto vuelve al `prompt`. Sigue sin autoenvío.
- Tests con `Transcription::fake()` y `preventStrayTranscription` si el SDK del minor lo expone (el de audio sí tiene `preventStrayAudio`; confirmar el de transcripción). Caso: driver `browser` ignora un upload. Caso: archivo grande o MIME raro falla con validación y no llama al provider.

Riesgo: el trait de uploads abre un endpoint de Livewire en ese componente. La acción tiene que salir antes de tocar disco útil si la config está apagada.

### 2.6 Cómo se actualiza una app que ya está en producción

`composer update cbstian/laravel-ai-chat-widget`. No hay `migrate`. El widget se ve igual.

Si publicaron `config/ai-chat.php`, copiar el bloque `voice` cuando quieran encenderlo. Mientras no esté, `data_get(..., false)` lo deja apagado.

No hace falta volver a correr `ai-chat:install`. No toca `app/Ai`.

---

## 3. Laravel MCP

### 3.1 Qué hay hoy en los paquetes

Versiones miradas el 2026-11-02 en Packagist y en la documentación de Laravel 13 (`/docs/mcp`, `/docs/ai-sdk`):

| Paquete | Versión vista | Restricciones | Relación con este paquete |
| --- | --- | --- | --- |
| `laravel/ai` | 1.3.0 (2026-10-19) | PHP ^8.3, illuminate ^12 \|\| ^13 | Este paquete ya exige `^1.0`. Ese rango incluye el soporte MCP que entró en la serie 0.8 y la aprobación de tools (0.10). |
| `laravel/mcp` | 1.1.2 (2026-10-26) | PHP ^8.2, illuminate ^11.45.3 \|\| ^12.41.1 \|\| ^13 | **No** está en `require` ni en `suggest`. `laravel/ai` lo declara `suggest` y entra en conflicto con `laravel/mcp` &lt; 1.0. |

Este paquete no debe pasar a `require` de `laravel/mcp`. Las apps que no tienen servidor MCP no deben instalarlo. `suggest` + `class_exists` en el catálogo.

#### Cómo se registra un servidor

En `routes/ai.php` de la app (publicado con el tag `ai-routes`):

- `Mcp::web('/mcp/...', WeatherServer::class)` — HTTP. Acepta `middleware()` (throttle, `auth:sanctum`, Passport).
- `Mcp::local('weather', WeatherServer::class)` — stdio, pensado para `php artisan mcp:start` y clientes de escritorio (Boost, editores). No arrastra la sesión web.

La clase servidor extiende `Laravel\Mcp\Server` y declara `$tools`, `$resources` y `$prompts`. Esas propiedades son **protected**. El constructor exige un `Transport`. No hay un `app(WeatherServer::class)->tools()` público y estable para listarlas sin transporte.

Las tools de servidor (`php artisan make:mcp-tool`) implementan `handle(Laravel\Mcp\Request)`, `schema()`, y pueden usar `shouldRegister()`, validación, inyección, y anotaciones `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`. Esas anotaciones salen como `readOnlyHint`, `destructiveHint`, etc. **No ejecutan una confirmación por sí solas.** La autorización de negocio es código dentro de `handle()` vía `$request->user()`.

`Laravel\Mcp\Request::user()` no lee un token mágico: llama al user resolver de `auth`. En un request de Livewire, eso es la persona logueada. En un proceso `mcp:start`, no hay usuario de sesión.

#### Qué sabe hacer `laravel/ai` con MCP

Documentado en el AI SDK, y presente en la rama `1.x`:

1. **Cliente HTTP o stdio.** `...Client::web($url)->withToken($token)->tools()`, `...Mcp::client('nombre')->tools()`, `...Client::local('php', ['artisan', 'mcp:start'])->tools()`. El SDK los envuelve en `Laravel\Ai\Tools\McpTool`. El esquema JSON se normaliza; si un esquema no entra en `illuminate/json-schema`, esa tool queda con propiedades vacías en lugar de tumbar el agente.
2. **Tool de servidor, in-process.** Se puede devolver `new App\Mcp\Tools\CurrentWeatherTool` desde `tools()`. El SDK lo envuelve en `Laravel\Ai\Tools\McpServerTool`, resuelve `handle()` por el contenedor y liga un `Laravel\Mcp\Request` armado con los argumentos. `$request->user()` sigue siendo el usuario del request HTTP actual. La respuesta MCP (`Response`, `ResponseFactory`, generadores) se aplana a texto; las notificaciones se tiran.
3. **Recursos y prompts no entran solos a un agente.** El cliente sí puede `prompts()`, `getPrompt()`, `resources()` y `readResource()`. `tools()` del agente solo recibe tools. Un recurso publicado en el servidor MCP no se convierte en contexto del chat si nadie lo lee.

Los dos wrappers exponen `annotations()` y un `needsApproval()` que **devuelve false**. O sea: una tool MCP conectada al agente se ejecuta al momento, aunque tenga `#[IsDestructive]`. `requireApproval()` del trait `InteractsWithApprovals` sí tiene prioridad sobre `needsApproval()` (`approvalRequirement` gana). Hay que confirmar en el minor instalado que el wrapper usa ese trait y que `requireApproval()` es público; el archivo en `1.x` define `needsApproval()`, el trait define `requireApproval()`. Si el minor de alguna app vieja de `^1.0` no trae el método, el catálogo no debe llamarlo: la allowlist es el control que sí podemos garantizar.

La aprobación de `laravel/ai` pausa el stream con un evento `tool_approval_request` (clase `Laravel\Ai\Streaming\Events\ToolApprovalRequest` desde 0.10) y se reanuda con `Decisions` pasado a `stream()` / `prompt()`, no con otro string. El turno queda `paused`. Este widget, hoy:

- ignora cualquier evento que no sea `ToolCall`, `StreamStart` o `TextDelta`;
- al terminar `ask()`, borra `pendingQuestion` y recarga solo mensajes `completed`;
- no tiene método para mandar `Decisions`.

Encender tools de escritura sin la fase de confirmación las ejecuta en cuanto el modelo las pide.

### 3.2 Alternativas

**(a) Cliente HTTP contra el `Mcp::web` de la misma app.**

Descartada como camino por defecto.

- El request de Livewire tendría que hacer otro HTTP a sí mismo. Con `php artisan serve` (un solo hilo) o con pocos workers PHP-FPM, eso se bloquea.
- La cookie de sesión no viaja sola en `Client::web`. Habría que emitir un token Sanctum o Passport por cada turno, o dejar el servidor MCP abierto.
- El middleware del route MCP (throttle, Passport) no es el mismo que el del panel Filament. Se puede terminar con otro usuario, o con ninguno.
- Suma latencia y un endpoint que quizá solo existía para clientes externos.

Sí tiene sentido para un MCP **de otra máquina** (GitHub, un servicio interno ya publicado). Eso ya lo puede hacer la app en su `ChatAgent`, con `Mcp::client('...')`, sin que este paquete invente un cliente.

**(b) In-process, las mismas clases `Tool` de `laravel/mcp`.**

Recomendada para el servidor de la app anfitriona.

- Es el camino que `laravel/ai` ya soporta. No reimplementamos JSON-RPC.
- `$request->user()` es la persona del chat. Las policies y los `Gate` que miran al usuario funcionan. `shouldRegister()` también, si el catálogo lo invoca antes de exponer la tool.
- No hace falta token, ni route MCP, ni proceso `artisan`.
- Lo que **no** corre: middleware HTTP del `Mcp::web`, OAuth, y cualquier lógica que dependa del `Transport` o del meta JSON-RPC. Si una tool asume que solo la llama un cliente OAuth externo, hay que mirarla antes de meterla en el allowlist.

Limitación práctica: `$tools` del servidor es protected y el constructor pide un `Transport`. El catálogo no debería parsear el servidor por reflexión como contrato estable. La config lista **clases de tool** (o un método público que la app añade en su servidor, ver fase M1). Las tools metidas en un `ToolSearch` no salen solas en una lista plana; quedan fuera hasta que alguien las nombre.

**(c) Proceso local `Client::local('php', ['artisan', 'mcp:start'])`.**

Descartada para este widget. El proceso no tiene la sesión. Las tools verían `user() === null` y fallarían la autorización, o peor, un `shouldRegister` mal escrito las dejaría pasar sin usuario. Sirve para Boost en la máquina del desarrollador, no para un panel con permisos.

**(d) No tocar el paquete y documentar el snippet.**

Válida como mínimo, y es la postura actual del paquete («las tools de negocio viven en la app»). El costo es que cada app copia el allowlist, el filtro de anotaciones y el `class_exists`. Un catálogo pequeño en el paquete evita esa copia y mantiene el default apagado. No sustituye al `ChatAgent`: el factory sigue siendo el dueño de las tools.

### 3.3 Seguridad

- **Allowlist vacía.** `mcp.enabled=true` con `tools: []` no expone nada. No hay modo «todas las tools del servidor».
- **El chat ya exige usuario autenticado** y el gate `ResolvesChatAccess`. Eso no reemplaza la policy de cada tool. Cada tool MCP debe seguir comprobando `$request->user()->can(...)`. El catálogo no relaja eso.
- **Confirmación de escritura**, cuando exista la fase M2: si `confirm_writes` es true (default en cuanto se encienda MCP), toda tool cuya anotación `readOnlyHint` no sea `true` se entrega con `requireApproval()`. Las de solo lectura no preguntan. `#[IsDestructive]` refuerza el pedido, no es la única señal: una tool sin anotaciones se trata como escritura. Es el default conservador.
- Hasta que el widget sepa reanudar un turno, `confirm_writes` no puede depender de una pausa que la UI pierde. Por eso M1 es solo allowlist de tools de lectura, y M2 añade la pausa.
- **Nombres.** Una tool MCP se llama como su nombre MCP (`current-weather`), no como el basename PHP. `tool_labels` tiene que aceptar esa clave. Si no está, sigue el `default_tool_label` («Consultando…»).
- **Argumentos en logs.** `verbose_logs` ya guarda `arguments_json` de cada tool. Un argumento con datos sensibles queda en `ai_chat_tool_call_logs`. No es nuevo, pero al conectar MCP el volumen y el tipo de datos cambian. Documentarlo; no ampliar el log en esta feature.
- **Recursos.** Leer un recurso puede ser tan sensible como una tool. No volcarlos al prompt del sistema en la v1.

### 3.4 Fases de MCP

#### Fase M1 — catálogo in-process, opt-in, sin UI nueva

Config:

```php
'mcp' => [
    'enabled' => env('AI_CHAT_MCP', false),
    // class-string de Laravel\Mcp\Server\Tool. Vacío = ninguna.
    'tools' => [],
    // Nombres MCP a excluir aunque la clase esté listada.
    'except' => [],
    // Reservado para M2. En M1 no pausa el stream.
    'confirm_writes' => true,
],
```

No poner clases de servidor enteras en la config. La app elige tool por tool. Si más adelante conviene derivarlas del servidor, que el servidor de la app exponga un método propio (por ejemplo `chatToolClasses(): array`) y la config apunte a esa clase con una clave aparte. No leer `$tools` por reflexión.

Piezas nuevas:

- `src/Mcp/McpToolCatalog.php` — si `laravel/mcp` no está, o `enabled` es false, devuelve `[]`. Si está, instancia cada clase, descarta las que no extienden `Laravel\Mcp\Server\Tool`, llama `shouldRegister()` cuando existe, aplica `except`, y devuelve las instancias. `laravel/ai` las envuelve al armar el request.
- El catálogo se usa **desde el agente de la app**, no desde el driver:

```php
public function tools(): iterable
{
    return [
        new ListMarkdownDocuments,
        new ReadMarkdownDocument,
        ...app(\Cbstian\AiChat\Mcp\McpToolCatalog::class)->tools(),
    ];
}
```

- Actualizar el stub `ChatAgent.php.stub` con esas líneas comentadas o activas. `ai-chat:install` no reescribe el agente existente: las apps en producción añaden el spread a mano. Así no cambia el comportamiento al hacer `composer update`.
- `composer.json` `suggest`: `laravel/mcp` ^1.1, texto que diga para qué sirve. No entra en `require`.
- `require-dev`: `laravel/mcp: ^1.1` para poder testear el catálogo en CI. No obliga a las apps. Revisar que el `prefer-lowest` del workflow siga resolviendo Laravel 12 y 13; `laravel/mcp` 1.1 pide illuminate reciente de la 12 (`^12.41.1`). Si `prefer-lowest` de este paquete baja de esa revisión, fijar el mínimo de dev o no bajar `laravel/mcp` de 1.1.
- Docs: `INTEGRATION.md`, guideline, skill. Dejar claro que el route `Mcp::web` no es necesario para el chat, y que las policies siguen dentro de cada tool.
- `tool_labels` de ejemplo con un nombre kebab, para que no se copie el basename por inercia.

Tests (sin red):

- Paquete de tests con `laravel/mcp` en dev: catálogo apagado devuelve `[]` aunque haya clases en config.
- Allowlist vacía devuelve `[]`.
- Una tool fixture con `shouldRegister(): false` no sale.
- `except` quita por nombre MCP.
- Clase que no es tool MCP se ignora.
- Un test de arquitectura o de composer: `composer.json` require no contiene `laravel/mcp`.
- El widget y el driver actuales siguen verdes sin config MCP. Ningún test levanta un servidor HTTP.

Fuera de M1, a propósito: cliente HTTP, recursos, prompts, UI de confirmación, decorar el agente por detrás del factory.

#### Fase M2 — confirmación de tools que escriben

Esta fase es la que de verdad cambia el widget. No empezar a meter tools de escritura en las apps antes.

- Contrato **nuevo y opcional** `ApprovesChatTools` (nombre a cerrar), implementado por `AgentChatDriver`. El widget hace `instanceof`. Un `ChatDriver` de una app que no lo implemente sigue funcionando; esas apps no ven botones de aprobar.
- No añadir métodos a `ChatDriver`. Eso sería ruptura de API (MAJOR en `1.x`; en `0.x` igual hay que anunciarlo, y hay apps reales).
- El catálogo, si `confirm_writes` es true, envuelve la tool en un adaptador del paquete que implementa `Laravel\Ai\Contracts\Tool` y `Approvable`, delega `description` / `schema` / `handle` y pide aprobación cuando `readOnlyHint !== true`. No depender de que `McpServerTool::requireApproval()` exista. El `handle` del adaptador puede llamar al `handle` de la tool MCP con el mismo truco de contenedor que `McpServerTool`, o devolver la tool MCP y aplicar `requireApproval()` solo si el método existe. La primera opción es más estable entre minors.
- `AiChatWidget` guarda aprobaciones pendientes (id, nombre, argumentos, motivo) al ver `ToolApprovalRequest`.
- Nueva acción `decide(array $decisions)` que llama al driver opcional con `Decisions::from(...)`. Autorizar la conversación con el usuario actual antes de reanudar (`conversationBelongsTo` o el equivalente que use el driver). El SDK avisa que la pausa no está atada al participante: sin ese chequeo, otro usuario con el id podría reanudar.
- El historial tiene que mostrar el turno en pausa. Hoy `completedMessages()` lo esconde. Ampliar la lectura solo cuando hay aprobaciones pendientes, sin cambiar lo que se muestra en el caso normal.
- Al aprobar o rechazar, el stream sigue y el widget vuelve al camino de `TextDelta`.
- Tests con `FakeChatAgent` / gateway fake de `laravel/ai` que emita una pausa, más un test de que un driver sin el contrato opcional no rompe `ask()`.

Riesgo de producto: un rechazo sin texto corta la generación; un rechazo con texto vuelve al modelo. La UI debe mandar un motivo corto («La persona no autorizó esta acción») para que el modelo responda en vez de quedarse mudo.

#### Fase M3 — recursos y prompts

Solo si una app los necesita dentro del chat.

- Dos tools del paquete, opt-in con otra clave (`mcp.resources`, `mcp.prompts`, default `false`): `ListMcpResources` / `ReadMcpResource` y `GetMcpPrompt`, leyendo por el cliente o por las clases del servidor in-process.
- No inyectar el cuerpo de los recursos en `instructions()`. Puede ser grande y puede incluir datos de otro usuario si el recurso no filtra.
- Los prompts MCP no sustituyen al `instructions()` del `ChatAgent`. Son plantillas que el modelo pide.

### 3.5 Cómo se actualiza una app que ya está en producción

1. `composer update cbstian/laravel-ai-chat-widget`. Sin migración. El chat sigue con las mismas tools.
2. Cuando quieran MCP: `composer require laravel/mcp:^1.1` en **esa** app (el suggest no instala solo), publicar `routes/ai.php` solo si además quieren clientes externos.
3. Copiar el bloque `mcp` al config publicado. `enabled` puede quedar en false hasta tener la lista.
4. Añadir el spread del catálogo en `app/Ai/ChatAgent.php`. Es un edit consciente. El update del paquete no lo hace.
5. Probar con una tool de lectura que ya compruebe `$request->user()`.
6. No apuntar el catálogo a un `Mcp::web` de la misma app.
7. Dejar `confirm_writes` en true y no listar tools de escritura hasta tener M2 en el widget.

Apps que no publiquen config y no toquen el agente: cero cambio de comportamiento.

---

## 4. Versionado y compatibilidad

Según `VERSIONING.md` y el estado `0.x`:

| Cambio | Tipo |
| --- | --- |
| Claves `voice` y `mcp` con default apagado | MINOR |
| `suggest` de `laravel/mcp` | MINOR, no afecta al resolve de quien no lo pide |
| `require-dev` de `laravel/mcp` | no es release de runtime; vigilar CI `prefer-lowest` |
| Contrato nuevo opcional, sin tocar `ChatDriver` | MINOR |
| Método nuevo en `ChatDriver` | evitarlo |
| Migración | no hace falta. Si algún día se guarda el toggle por usuario, columna nueva = MINOR; no puede ser obligatoria para arrancar |
| Subir el mínimo de `laravel/ai` dentro de `^1.0` | no hace falta si el catálogo se protege con `method_exists` / `class_exists` |

`CHANGELOG.md` (Unreleased) se actualiza en el PR que implemente cada fase, no en este plan.

El matrix del paquete sigue PHP 8.3+, Laravel 12/13, Livewire 4, `laravel/ai` ^1.0. La voz en el navegador no depende de eso. La transcripción de servidor y el wrapper MCP dependen de que el minor de `laravel/ai` instalado en cada app tenga `Transcription` y `McpServerTool`. Con el constraint actual, una app en `1.0.0` ya debería tenerlos (llegaron antes del 1.0). Confirmarlo en la app más antigua antes de M2, porque la aprobación de tools es de la serie 0.10 y el wrapper puede haber ganado `needsApproval()` después.

---

## 5. Riesgos

- **Audio a terceros sin que se note.** Web Speech en Chrome no es local. Si una app maneja datos sensibles, V1 puede ser inaceptable y hay que ir a V2 con el provider que ya tengan contrato.
- **Firefox.** Sin fallback, el botón no aparece. No es un error de Laravel.
- **Config publicada.** `mergeConfigFrom` no rellena un `config/ai-chat.php` ya copiado. Olvidar el default en código encendería la función con `null` mal interpretado, o lanzaría un error al leer `voice.locale`. Siempre `data_get` con default.
- **Autoenvío del dictado.** Un reconocimiento malo dispararía tools MCP. Por eso el texto pasa por el textarea.
- **Allowlist amplia.** La tentación va a ser registrar el servidor entero. Una tool sin `user()->can()` quedaría al alcance de quien ya entra al chat.
- **Pausa invisible.** Conectar una tool `Approvable` antes de M2 deja el turno en `paused` y el widget lo esconde. La persona ve que «no pasó nada» y el modelo tampoco sigue.
- **Deadlock HTTP.** Alguien va a probar `Client::web(config('app.url').'/mcp/...')` desde el agente, dentro del mismo PHP. Hay que decirlo en la guía como algo que no se hace.
- **stdio sin usuario.** `Client::local` + `mcp:start` desde el chat pierde el permiso de la persona.
- **Esquemas MCP raros.** `McpTool` traga el esquema y puede publicar la tool sin argumentos. El modelo llama mal y la tool valida. No es un fallo del widget, pero hay que esperarlo con servidores MCP generados fuera de Laravel.
- **Logs de argumentos.** Más tools, más datos en `ai_chat_tool_call_logs`.
- **Nombres de tool.** `tool_labels` con el basename PHP no va a coincidir con `current-weather`. El rótulo del stream se queda en «Consultando…».
- **Stub vs apps ya instaladas.** Cambiar el stub no actualiza `app/Ai/ChatAgent.php`. Hay que decirlo en el release, no asumirlo.
- **CI.** `laravel/mcp` en `require-dev` puede subir el piso de illuminate en el lane más bajo.

---

## 6. Decisiones que tiene que tomar el autor

1. ¿El dictado solo rellena el textarea (recomendado) o hay un modo que envía al soltar?
2. ¿`es-CL` fijo de paquete, o cada app lo setea y con eso basta? ¿Hace falta un locale por usuario?
3. ¿Las apps de producción pueden aceptar que Chrome mande el audio a Google? Si no, V1 no se enciende en esas apps y V2 pasa a ser el único camino.
4. ¿Firefox (escritorio) es un requisito de las apps actuales? Define si V2 entra en el mismo release que V1.
5. ¿MCP de la primera entrega es solo tools de lectura de la propia app, o también un cliente con nombre ya registrado (`Mcp::client('github')`)?
6. ¿Quién arma la allowlist: una lista de clases en `config/ai-chat.php` (recomendado) o un método en el servidor MCP de cada app?
7. ¿Se puede esperar a M2 antes de exponer cualquier tool que no sea de solo lectura? Recomendación: sí.
8. ¿Recursos y prompts entran en el alcance de este trabajo o se quedan fuera hasta que una app los pida?
9. ¿El toggle por panel Filament hace falta en V1, o el flag global y el argumento `voice` del componente alcanzan?
10. ¿Estas fases salen como minors `0.x` separados (voz, luego MCP, luego confirmación) o se espera a juntarlas? Separadas reducen el riesgo en las apps que ya están en `dev-master`.

---

## 7. Esfuerzo

Tamaño del cambio, no calendario. Un PR por fase, en este orden.

| Fase | Tamaño | Qué se toca | Depende de |
| --- | --- | --- | --- |
| V1 Voz en el navegador | Pequeño | Vista, CSS, bloque de config, props del componente, tests de render, tres docs de integración | Nada de `laravel/ai` ni de MCP |
| V2 Transcripción de servidor | Medio | Upload Livewire, límites, borrado, `Transcription::fake()`, config de provider | V1, y un minor de `laravel/ai` con `Transcription` (ya cubierto por `^1.0`) |
| M1 Catálogo MCP | Medio | Clase nueva, `suggest`, `require-dev`, stub comentado, tests del catálogo, docs. Sin cambio de UI | `laravel/mcp` solo en dev y en las apps que lo enciendan |
| M2 Confirmación en el widget | Grande | Contrato opcional, estado Livewire, evento de pausa, `Decisions`, historial `paused`, CSS de la tarjeta de confirmación, tests de stream | M1 si la confirmación es para tools MCP; el mecanismo también serviría para tools `Approvable` propias de la app |
| M3 Recursos y prompts | Medio | Tools nuevas opt-in y tests con el servidor MCP de fixture | M1 |

Orden sugerido: **V1, después M1, después M2, y V2 solo si el navegador no alcanza.** M3 cuando haya un caso real.

V1 y M1 se pueden revisar en paralelo porque no comparten archivos de lógica, pero no hace falta mezclarlos en un solo release: las apps en VCS se mueven con `dev-master` y un release pequeño se revierte mejor.

---

## 8. Referencias

- Docs Laravel MCP (servidores web y locales, tools, anotaciones, auth Sanctum/Passport, cliente, recursos, prompts): https://laravel.com/docs/mcp
- Docs Laravel AI SDK (MCP tools, transcripción, aprobación de tools, evento `tool_approval_request`): https://laravel.com/docs/ai-sdk
- `laravel/ai` 1.3.0 sugiere `laravel/mcp` ^1.0 y envuelve tools de cliente y de servidor (`src/Tools/McpTool.php`, `src/Tools/McpServerTool.php` en la rama 1.x).
- `Laravel\Mcp\Request::user()` usa el user resolver de `auth` (rama main de `laravel/mcp`, 2026-11-02).
- Web Speech: MDN `SpeechRecognition`; soporte parcial en Chrome y Safari, Firefox desactivado (Can I use, 2026). El audio de Chrome se procesa en un servicio de red salvo `processLocally`.
- Política interna: `VERSIONING.md`, `docs/INTEGRATION.md`, `AGENTS.md`.
