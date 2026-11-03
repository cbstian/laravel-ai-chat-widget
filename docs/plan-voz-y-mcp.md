# Plan: dictado por voz e integración con Laravel MCP

Estado: **análisis y plan**. No hay código de estas funciones en el paquete.
Fecha de la investigación: 2026-11-02. Decisión de voz del autor incorporada el mismo día.
Paquete: `cbstian/laravel-ai-chat-widget` (pre-`1.0`, instalaciones de producción vía VCS).

Este documento no es API pública. La política de versionado trata `docs/` como interno salvo que un release lo indique.

## Recomendación corta

1. **Voz.** STT con drivers, todo apagado por defecto. `voice.driver` elige uno: `browser` (Web Speech API, sin costo de API), `laravel-ai` (`Laravel\Ai\Transcription` con el proveedor que el SDK sí transcribe) o `xai` (cliente propio del paquete contra `POST https://api.x.ai/v1/stt`, porque `laravel/ai` no hace STT en xAI y ese endpoint no es el de OpenAI). Un manager estilo Laravel permite que la app registre otro driver. El texto rellena el textarea y no se envía solo, salvo `voice.auto_submit`. El WebSocket de xAI queda fuera de estas fases.
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

La voz no existe en el código. Cuando se implemente, el manager de STT queda al lado del widget: no pasa por `ChatDriver` ni por `ask()`.

---

## 2. Dictado por voz

Decisión del autor: un solo interruptor (`voice.enabled`, default `false`) y un driver (`voice.driver`). No hay fallback automático de navegador a servidor. Quien elige `browser` no graba para Laravel; quien elige `laravel-ai` o `xai` no usa Web Speech. Cambiar de driver es un cambio de config, no un segundo motor en paralelo.

### 2.1 Drivers

| Driver | Dónde corre | Cuándo usarlo |
| --- | --- | --- |
| `browser` | `SpeechRecognition` / `webkitSpeechRecognition` en el navegador | Sin costo de API. Chrome, Edge y Safari 14.1+ / iOS 14.5+. Firefox de escritorio no lo tiene habilitado. En Chrome el audio va al servicio de Google (o al de Microsoft en Edge; Apple en Safari), no a la app. |
| `laravel-ai` | `MediaRecorder` en el navegador, transcripción en PHP con `Laravel\Ai\Transcription` | El SDK, en la matriz publicada el 2026-11-02, transcribe con OpenAI, OpenAI-compatible, ElevenLabs, Groq, Mistral, Gemini y OpenRouter. xAI no está en esa fila. |
| `xai` | El mismo audio grabado, pero un cliente HTTP del paquete a `POST https://api.x.ai/v1/stt` | Grok. Hace falta porque el endpoint no es `/audio/transcriptions` ni acepta el cuerpo de OpenAI. Meterlo como provider `openai-compatible` de `laravel/ai` mandaría el archivo al path equivocado. |
| nombre que registre la app | Clase propia vía el manager | Otro proveedor, sin fork del paquete. |

`laravel/ai` 1.3.0 sí habla con xAI para texto, imágenes y algunas tools. La fila STT de la documentación no lo incluye. Un `Transcription::...->generate(provider: 'xai')` no es el plan.

Precio REST de xAI indicado para este plan: USD 0,10 por hora de audio. La página de capacidades consultada describe el contrato y los formatos; no repite la tarifa en el extracto. El WebSocket se evalúa en la fase V3 y no entra en V1 ni en V2.

### 2.2 Contrato y manager

Contrato nuevo, público en cuanto se publique (MINOR, no toca `ChatDriver`):

```php
namespace Cbstian\AiChat\Contracts;

interface SpeechToTextDriver
{
    /** true: transcribe el navegador. false: el widget sube audio y llama a transcribe(). */
    public function runsInBrowser(): bool;

    public function transcribe(SpeechAudio $audio): SpeechTranscript;
}
```

- `SpeechAudio`: ruta temporal o stream, MIME, nombre de archivo, bytes y duración que declara el cliente (no fiarse de ella para el tope; el tope real es el tamaño).
- `SpeechTranscript`: `text` obligatorio. `language` y `duration` opcionales, solo para el que llama. El widget usa `text`. No se guardan `words`.
- El driver `browser` devuelve `runsInBrowser(): true`. Su `transcribe()` lanza si alguien lo invoca: ese camino no debe salir a la red.

Manager estilo Laravel, registrado en el contenedor (`Cbstian\AiChat\Speech\SpeechToTextManager`):

- `driver()` sin argumentos usa `config('ai-chat.voice.driver')`.
- Drivers de paquete: `browser`, `laravel-ai`, `xai`.
- La app añade uno en el `boot` de un service provider:

```php
app(\Cbstian\AiChat\Speech\SpeechToTextManager::class)->extend(
    'acme',
    fn () => new \App\Speech\AcmeSpeechToTextDriver,
);
```

Un nombre desconocido no cae en `browser`. El widget trata la voz como apagada y reporta el error. Caer en el navegador sería mandar audio a Google cuando la app pidió Grok, o al revés.

La extensión prevista es un driver de servidor. Un driver de navegador propio exigiría un gancho JS que este plan no promete.

`SpeechToTextDriver` no se añade a `ChatDriver`. Las apps con un driver de chat propio no tienen que implementarlo.

### 2.3 Config

Todas las claves se leen con `data_get` y default. Un `config/ai-chat.php` publicado y viejo no tiene el bloque `voice`: eso equivale a apagado.

```php
'voice' => [
    'enabled' => env('AI_CHAT_VOICE', false),
    'driver' => env('AI_CHAT_VOICE_DRIVER', 'browser'),
    // BCP 47. El browser lo usa tal cual. laravel-ai y xai lo mapean (ver abajo).
    'language' => env('AI_CHAT_VOICE_LANGUAGE', 'es-CL'),
    'auto_submit' => env('AI_CHAT_VOICE_AUTO_SUBMIT', false),
    'user_can_disable' => true,
    'panels' => null,

    'max_seconds' => (int) env('AI_CHAT_VOICE_MAX_SECONDS', 60),
    'max_kilobytes' => (int) env('AI_CHAT_VOICE_MAX_KB', 2048),
    'rate_limit_per_minute' => (int) env('AI_CHAT_VOICE_RATE_LIMIT', 10),

    'browser' => [
        'interim_results' => true,
        'continuous' => false,
    ],

    'laravel-ai' => [
        // Vacío: el driver elige un provider con STT, no asume que ai.default sepa transcribir.
        'provider' => env('AI_CHAT_VOICE_PROVIDER'),
        'model' => env('AI_CHAT_VOICE_MODEL'),
        'diarize' => false,
    ],

    'xai' => [
        'key' => env('XAI_API_KEY'),
        'url' => env('AI_CHAT_XAI_STT_URL', 'https://api.x.ai/v1/stt'),
        'model' => env('AI_CHAT_XAI_STT_MODEL', 'grok-voice-transcribe-2.0'),
        // ITN: números y montos en forma escrita. Exige language. Default true.
        'format' => env('AI_CHAT_XAI_STT_FORMAT', true),
        'filler_words' => false,
        'keyterms' => [],
        'timeout' => 30,
    ],
],
```

`enabled` en false gana siempre, aunque `driver` esté puesto. El default de `driver` es `browser` para que, el día que alguien encienda solo el flag, no empiece a subir audio ni a gastar API. Encender voz de pago es una segunda decisión explícita (`AI_CHAT_VOICE_DRIVER=laravel-ai` o `xai`).

Niveles, con la misma precedencia de antes: el servidor manda y el cliente solo puede apagar.

| Nivel | Qué es | Default |
| --- | --- | --- |
| App | `voice.enabled`. Cada instalación tiene su config. | `false` |
| Driver | `voice.driver` | `browser` (no hace nada mientras `enabled` sea false) |
| Panel Filament | `voice.panels`: `null` o lista de ids. Fuera de Filament no aplica. | `null` |
| Pantalla | `@livewire('ai-chat-widget', ['voice' => false])`, prop `#[Locked]`. No va dentro de `context`. | hereda la config. Solo puede forzar false |
| Persona | Toggle en `localStorage` (`pc-ai-chat-voice`) si `user_can_disable` | oculta el botón; no cambia el driver ni enciende la función |

Sin columna nueva y sin migración.

#### Idioma

Una sola clave, `voice.language`, default `es-CL`.

| Driver | Qué se envía |
| --- | --- |
| `browser` | `recognition.lang = 'es-CL'`. Si queda vacío, el navegador usa el `lang` del HTML. |
| `laravel-ai` | El subtag primario ISO 639-1: `es-CL` → `es`. Whisper y el resto del SDK no distinguen el acento. El builder público hay que confirmarlo en el minor instalado: los tests del SDK leen `$prompt->language`; la guía de transcripción no muestra el método en el extracto del 2026-11-02. |
| `xai` | `language` del multipart. Español documentado: `es` (el modelo lo trata como español de México) y `es-ES`. No hay `es-CL`. Mapear `es-CL` y `es-MX` a `es`, y dejar pasar `es-ES` si la app lo configuró. La respuesta trae el idioma detectado en BCP 47 (`es-mx`, etc.); no se usa para el textarea. |

`format=true` en xAI sin `language` responde 400. Si el mapeo no produce idioma, el driver manda la transcripción sin `format`.

#### Audio que graba el navegador

Un solo `MediaRecorder`, con el primer MIME que `isTypeSupported` acepte, en este orden:

1. `audio/webm;codecs=opus` — Chrome, Edge, Firefox.
2. `audio/webm`.
3. `audio/mp4` — Safari (AAC dentro de MP4). En Safari viejo `MediaRecorder` no existe: el botón de los drivers de servidor no se muestra y el error es de UI, no una excepción de PHP.

xAI auto-detecta contenedores. Acepta `webm`, `mp4`, `m4a`, `ogg`, `opus`, `wav`, `mp3`, `flac`, `aac` y otros. Para esos formatos no hay que mandar `audio_format` ni `sample_rate` (esos campos son para PCM/mulaw/alaw sin cabecera). El gateway OpenAI de `laravel/ai` ya nombra `webm` y `m4a` según el MIME. Groq, Gemini, ElevenLabs, Mistral y OpenRouter están en la matriz STT, pero el paquete no transcodifica: no se añade ffmpeg. Si un provider rechaza WebM, la app cambia de provider o de driver. El plan no promete un formato único para los siete.

Tope del paquete: 60 segundos y 2 MB. xAI acepta hasta 500 MB; ese techo no se usa. El cliente corta la grabación al llegar a `max_seconds`. El servidor rechaza por tamaño y por MIME, no por la duración que declare el JS.

### 2.4 Driver `laravel-ai`

`Transcription::fromUpload($file)` (o `fromPath` del temporal), con `provider` y `model` de `voice.laravel-ai` cuando vienen llenos. `diarize` default false: en un dictado de una persona no aporta.

Si `provider` está vacío, no usar ciegamente `config('ai.default')`. Ese default puede ser `xai` o `anthropic`, que no transcriben. El driver exige `voice.laravel-ai.provider` o, si se quiere un atajo, solo acepta el default cuando ese provider está en la lista STT del SDK. Si no, error claro en el panel y cero requests.

El idioma va en el builder cuando el minor lo permita. No activar diarización ni colas (`queue()`): el usuario está esperando el textarea.

### 2.5 Driver `xai`

`POST https://api.x.ai/v1/stt` con `Authorization: Bearer`. La key sale de `voice.xai.key` (`XAI_API_KEY`) y, si viene vacía, de `config('ai.providers.xai.key')` cuando `laravel/ai` ya la tenga. Hay que confirmar esa ruta en el `config/ai.php` publicado de la app al implementar. La key no se renderiza en Blade, ni en el JSON de Alpine, ni en los mensajes de error.

Multipart, en este orden, porque xAI ignora los campos que van después de `file`:

1. `model` — default `grok-voice-transcribe-2.0`.
2. `language` — el mapeo de 2.3.
3. `format` — `true` o se omite. Nunca `true` sin idioma.
4. `filler_words` — default false (xAI ya quita «eh», «um»).
5. `keyterm` — un part repetido por término, no `keyterm[0]`. Máximo 100 términos de 50 caracteres; el driver recorta y descarta el resto. El cliente HTTP de Laravel, si recibe un array, puede serializarlo como arreglo PHP: hay que armar el body a mano.
6. `file` — último. Nombre con extensión acorde al MIME (`audio.webm`, `audio.mp4`).

No usar el campo `url` de xAI. Evita que el proveedor descargue una URL y cierra un vector de SSRF si algún día el argumento saliera del usuario.

De la respuesta JSON se lee `text`. `language`, `duration` y `words` no se persisten. `words` puede ser largo y no mejora el chat.

Errores que ve la persona, sin el cuerpo crudo del upstream: 401 «no se pudo transcribir», 413 no debería ocurrir con el tope de 2 MB, 429 «demasiadas transcripciones», timeout a los 30 s. Un 400 por `format` sin idioma es un bug del driver, no un mensaje para el usuario final.

### 2.6 WebSocket de xAI (fase futura, no V1 ni V2)

Existe `wss://api.x.ai/v1/stt` (mismo modelo por defecto, query params, audio binario, eventos `transcript.created`, `transcript.partial`, `transcript.done`). No entra ahora.

- La documentación dice que la API key no puede ir al navegador. Haría falta un proxy en Laravel que sostenga el socket.
- PHP-FPM atiende un request y se va. Un dictado en vivo no cabe en la acción Livewire que usa el resto del widget.
- `MediaRecorder` entrega un contenedor WebM u MP4. El modo `encoding=opus` del WebSocket quiere un paquete Opus crudo por frame, no el contenedor. PCM a 16 kHz exige resamplear en el cliente. El REST sí acepta el contenedor tal cual.
- La UX elegida es pulsar, hablar, soltar y revisar. El REST alcanza. El socket aportaría interinos parecidos a Web Speech, con más piezas.

V3, si algún día se hace: proxy de servidor, key solo en PHP, y un encoder en el navegador. Hasta entonces no hay claves `voice.xai.stream`.

### 2.7 UI

Común a los tres drivers. Alpine en la vista, sin Vite.

Estados del botón: idle, pidiendo permiso, grabando (o escuchando, en `browser`), transcribiendo, error. El permiso denegado deja un texto en el panel y no reintenta solo. Mientras graba o transcribe, el enviar queda deshabilitado para no mandar un texto a medias.

`browser`: una toma (`continuous: false`), interinos en el textarea si `interim_results` es true, el resultado final reemplaza al interino. Al terminar, el foco vuelve al input.

`laravel-ai` y `xai`: pulsar empieza `MediaRecorder`, volver a pulsar (o el tope de segundos) lo detiene, el blob se sube, el botón muestra «transcribiendo…». El texto que vuelve se concatena al `prompt` con un espacio si ya había algo escrito. No sustituye lo que la persona había tipeado.

`voice.auto_submit` default `false`. En true, un dictado no vacío llama a `submit()` después de escribir el texto. El default evita que un reconocimiento malo dispare tools.

El JSON que llega al navegador es el modo (`browser` o `upload`), el idioma (solo hace falta en `browser`), `max_seconds`, `auto_submit` y los MIME preferidos. No lleva nombre de proveedor, modelo ni key. Así el DOM no dice si detrás hay Grok u OpenAI.

Subida: acción Livewire `transcribe` con `WithFileUploads`, no una route nueva del paquete. El widget vive dentro de Filament, y el guard del panel no es necesariamente el `auth` por defecto: una route con middleware `auth` respondería 401 a quien sí puede usar el chat. La acción corre en el mismo request autenticado que `ask()`. El trait abre el upload temporal de Livewire en ese componente; `transcribe` sale antes de leer el archivo si la voz está apagada, si el driver es `browser`, si `canAccess()` es false o si el MIME no está en la lista.

### 2.8 Seguridad

- `canAccess()` y usuario no nulo, igual que el chat. Un invitado no llega a la acción.
- Rate limit propio `ai-chat:voice:{id}`, default 10 por minuto, aparte de los 20 mensajes del chat.
- Tope 2 MB y MIME allowlist (`audio/webm`, `audio/ogg`, `audio/mp4`, `audio/mpeg`, `audio/wav`, `audio/x-m4a` y variantes con `codecs`). El resto se rechaza sin llamar al proveedor.
- El temporal se borra en un `finally`, también si la transcripción lanza. No se copia a `storage` permanente ni a `ai_chat_turn_logs`.
- La API key solo existe en el servidor. Un 401 de xAI no devuelve el body. El config publicado debe seguir leyendo `env('XAI_API_KEY')`: pegar la key en el PHP y commitearlo es un leak de la app, y la guía tiene que decirlo.
- `auto_submit` no lo puede activar el navegador: es config, no un parámetro del request.
- La prop `voice` del mount no puede pasar de false a true.

### 2.9 Fases

#### V1 — contrato, manager y driver `browser`

El bloque de config de 2.3 entra entero, para no volver a cambiar la forma en V2. Solo funciona `browser`.

Si `enabled` es true y el driver es `laravel-ai`, `xai` u otro nombre aún no implementado, el micrófono no se muestra y se reporta. No se disfraza de Web Speech.

Archivos: `config/ai-chat.php`, `src/Contracts/SpeechToTextDriver.php`, DTOs `SpeechAudio` y `SpeechTranscript`, `src/Speech/SpeechToTextManager.php`, driver `browser`, vista, CSS, props `#[Locked]` en el widget, `INTEGRATION.md`, guideline y skill. El stub del agente no cambia. `ask()` no cambia. Sin migración.

Tests:

- Defaults: `enabled` false, `driver` browser, `language` es-CL, `auto_submit` false, modelo xAI `grok-voice-transcribe-2.0`.
- Con el default, el HTML no tiene botón ni `SpeechRecognition`.
- Con `enabled` true y driver `browser`, el botón está y el `lang` es `es-CL`. No hay URL de upload ni key en el HTML.
- `panels` que no incluye el panel actual, o `voice: false` en el mount, ocultan el botón.
- Driver `xai` con la voz encendida, en V1, no muestra el botón.
- Invitado sin panel. `submit()` / `ask()` iguales.
- `extend()` resuelve un driver falso. Un nombre desconocido no resuelve `browser`.

El micrófono de verdad se prueba en el workbench, en Chrome y Safari. Pest no abre navegador.

#### V2 — `laravel-ai` y `xai`

Misma UI de grabar/detener/error, más la acción `transcribe`. Los dos drivers de servidor salen juntos: comparten grabación, límites y borrado; solo cambia quién recibe los bytes.

Tests:

- `Transcription::fake()`: un webm válido deja el texto en `prompt` y no llama a xAI. Aserción del idioma `es` cuando el builder del minor lo exponga. `preventStrayTranscription` si existe (el de audio tiene `preventStrayAudio`; confirmar el de transcripción).
- `Http::fake()` para xAI: URL `https://api.x.ai/v1/stt`, Bearer, `model`, `language=es` cuando la config dice `es-CL`, `format=true`, `keyterm` repetido, `file` como último part. La respuesta usa `text`.
- Sin `XAI_API_KEY` ni key en `config/ai.php`, no hay request.
- Driver `browser`: `Http::assertNothingSent()` y `Transcription::assertNothingGenerated()` aunque alguien invoque `transcribe` en el componente.
- Archivo grande o MIME `text/plain`: validación, nada de red, temporal borrado.
- Rate limit. Usuario sin acceso. `auto_submit` false no llama a `ask()`.
- `format` no se envía si no hay idioma.

#### V3 — WebSocket xAI

No planificar archivos hasta que V2 esté en uso. Ver 2.6.

### 2.10 Apps que ya están en producción

`composer update cbstian/laravel-ai-chat-widget`. No hay `migrate`. El chat se ve igual mientras `AI_CHAT_VOICE` no exista o sea false.

Quien publicó `config/ai-chat.php` copia el bloque `voice` solo cuando lo va a encender. Hasta entonces el default en código lo deja apagado.

No hace falta `ai-chat:install`. No se toca `app/Ai`. La key de xAI, si ya está en el `.env` de `laravel/ai`, se reutiliza; no hace falta otra.

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
| `SpeechToTextDriver`, `SpeechAudio` y `SpeechTranscript` | MINOR. Pasan a ser API pública. Conviene cerrar los métodos en V1 (`runsInBrowser`, `transcribe`) para no añadir otro en V2: un método nuevo rompe los drivers que la app haya registrado |
| `suggest` de `laravel/mcp` | MINOR, no afecta al resolve de quien no lo pide |
| `require-dev` de `laravel/mcp` | no es release de runtime; vigilar CI `prefer-lowest` |
| Contrato nuevo opcional de aprobación MCP, sin tocar `ChatDriver` | MINOR |
| Método nuevo en `ChatDriver` | evitarlo |
| Migración | no hace falta. Si algún día se guarda el toggle por usuario, columna nueva = MINOR; no puede ser obligatoria para arrancar |
| Subir el mínimo de `laravel/ai` dentro de `^1.0` | no hace falta. El driver `laravel-ai` usa `Transcription`, presente en `^1.0`. El driver `xai` es HTTP propio y no depende de un gateway del SDK |

`CHANGELOG.md` (Unreleased) se actualiza en el PR que implemente cada fase, no en este plan.

El matrix del paquete sigue PHP 8.3+, Laravel 12/13, Livewire 4, `laravel/ai` ^1.0. El driver `browser` no llama al SDK. El driver `laravel-ai` necesita `Transcription` en ese rango. El wrapper MCP necesita `McpServerTool`. Confirmarlo en la app más antigua antes de M2, porque la aprobación de tools es de la serie 0.10 y el wrapper puede haber ganado `needsApproval()` después. El driver `xai` no pide un paquete nuevo.

---

## 5. Riesgos

- **Audio a terceros.** `browser` en Chrome manda el audio a Google. `laravel-ai` y `xai` lo mandan al proveedor elegido. La UI tiene que decir cuál de los dos mundos está activo, sin nombrar la key.
- **Driver equivocado por silencio.** Si V1 viera `driver=xai` y usara Web Speech, la app creería que transcribe Grok. Un driver no implementado o desconocido deja el micrófono oculto.
- **Firefox y Safari viejo.** Sin `SpeechRecognition` el driver `browser` no muestra botón. Sin `MediaRecorder` los drivers de servidor tampoco. No es un fallo de PHP.
- **`es-CL` en xAI.** No es un código regional documentado (`es` / `es-ES`). Mandarlo crudo puede sesgar mal o rechazar el request. El mapeo a `es` es parte del driver, no un detalle de la vista.
- **Orden del multipart.** Un `keyterm` después de `file`, o un array PHP `keyterm[0]`, no sesga el modelo. Hay que afirmar el orden en el test de `Http::fake`.
- **`format=true` sin idioma.** xAI responde 400. El driver no debe poder llegar a esa combinación.
- **WebM contra un provider de `laravel/ai`.** El paquete no transcodifica. Si Gemini u otro rechaza el contenedor de Chrome, falla esa toma; la salida es cambiar provider, no añadir ffmpeg.
- **Key en el front o en el config commiteado.** El Blade solo recibe el modo de UI. `XAI_API_KEY` se lee con `env()` o desde `config/ai.php`.
- **Temporales de Livewire.** El `finally` tiene que borrar el archivo también cuando el provider lanza. No dejar el audio en el disco de uploads.
- **Config publicada.** `mergeConfigFrom` no rellena un `config/ai-chat.php` ya copiado. Olvidar el default en código encendería la función con `null` mal interpretado. Siempre `data_get` con default. `enabled` ausente es false.
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

## 6. Decisiones

Cerrado para la voz:

1. STT por drivers. `browser`, `laravel-ai` y `xai`. Manager extensible. Todo apagado hasta que `voice.enabled` sea true.
2. El texto rellena el textarea. `voice.auto_submit` existe y default false.
3. Un idioma de config, default `es-CL`, mapeado por driver. No hace falta un locale por usuario en estas fases.
4. El WebSocket de xAI no entra en V1 ni en V2.
5. La subida va por una acción Livewire, no por una route nueva, por el guard de Filament.

Sigue abierto:

1. ¿MCP de la primera entrega es solo tools de lectura de la propia app, o también un cliente con nombre ya registrado (`Mcp::client('github')`)?
2. ¿Quién arma la allowlist: una lista de clases en `config/ai-chat.php` (recomendado) o un método en el servidor MCP de cada app?
3. ¿Se puede esperar a M2 antes de exponer cualquier tool que no sea de solo lectura? Recomendación: sí.
4. ¿Recursos y prompts entran en el alcance de este trabajo o se quedan fuera hasta que una app los pida?
5. ¿El filtro `voice.panels` hace falta en V1, o el flag global y el argumento `voice` del componente alcanzan?
6. ¿V1, V2 y las fases MCP salen como minors `0.x` separados? Separadas se revierten mejor en las apps que siguen `dev-master`.

---

## 7. Esfuerzo

Tamaño del cambio, no calendario. Un PR por fase, en este orden.

| Fase | Tamaño | Qué se toca | Depende de |
| --- | --- | --- | --- |
| V1 Driver `browser` | Pequeño, tirando a medio | Contrato, DTOs, manager, config completa, Alpine de escucha, tests de render y de `extend()`. Sin red | Nada de MCP. No llama a `Transcription` |
| V2 Drivers `laravel-ai` y `xai` | Medio, tirando a grande | `MediaRecorder`, acción `transcribe`, borrado, MIME, `Transcription::fake()`, `Http::fake()` con el orden del multipart, mapeo `es-CL` → `es` | V1. `Transcription` ya entra en `laravel/ai` ^1.0. xAI es HTTP, sin paquete nuevo |
| V3 WebSocket xAI | Grande, diferida | Proxy que sostenga el socket, encoder Opus o PCM en el navegador, key solo en el servidor | V2 en uso, y un caso que el REST no cubra |
| M1 Catálogo MCP | Medio | Clase nueva, `suggest`, `require-dev`, stub comentado, tests del catálogo, docs. Sin cambio de UI | `laravel/mcp` solo en dev y en las apps que lo enciendan |
| M2 Confirmación en el widget | Grande | Contrato opcional, estado Livewire, evento de pausa, `Decisions`, historial `paused`, CSS de la tarjeta de confirmación, tests de stream | M1 si la confirmación es para tools MCP; el mecanismo también serviría para tools `Approvable` propias de la app |
| M3 Recursos y prompts | Medio | Tools nuevas opt-in y tests con el servidor MCP de fixture | M1 |

Orden sugerido: **V1, V2, y en paralelo o después M1 y M2.** V3 no se agenda. M3 cuando haya un caso real.

V1 y M1 casi no comparten archivos. Aun así, un release por fase: las apps en VCS se mueven con `dev-master` y un cambio chico se revierte mejor. V2 no debe publicarse antes de que el contrato de V1 esté cerrado, porque un driver de la app implementa esa interfaz.

---

## 8. Referencias

- Docs Laravel MCP (servidores web y locales, tools, anotaciones, auth Sanctum/Passport, cliente, recursos, prompts): https://laravel.com/docs/mcp
- Docs Laravel AI SDK (MCP tools, transcripción, aprobación de tools, evento `tool_approval_request`): https://laravel.com/docs/ai-sdk
- `laravel/ai` 1.3.0 sugiere `laravel/mcp` ^1.0 y envuelve tools de cliente y de servidor (`src/Tools/McpTool.php`, `src/Tools/McpServerTool.php` en la rama 1.x).
- `Laravel\Mcp\Request::user()` usa el user resolver de `auth` (rama main de `laravel/mcp`, 2026-11-02).
- Web Speech: MDN `SpeechRecognition`; soporte parcial en Chrome y Safari, Firefox desactivado (Can I use, 2026). El audio de Chrome se procesa en un servicio de red salvo `processLocally`.
- xAI Speech to Text (REST, formatos, `language`, `format`, `keyterm`, campo `file` al final): https://docs.x.ai/developers/model-capabilities/audio/speech-to-text
- xAI `POST /v1/stt` y WebSocket `wss://api.x.ai/v1/stt`: https://docs.x.ai/developers/rest-api-reference/inference/speech-to-text
- Matriz de capacidades de `laravel/ai` (STT sin xAI): https://laravel.com/docs/ai-sdk
- Política interna: `VERSIONING.md`, `docs/INTEGRATION.md`, `AGENTS.md`.
