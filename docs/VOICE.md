# Dictado por voz

Guía para incorporar o actualizar el dictado de `cbstian/laravel-ai-chat-widget` en una app Laravel. El flujo general del paquete sigue en [INTEGRATION.md](INTEGRATION.md).

El micrófono está **apagado por defecto**. Un `composer update` no lo muestra y no cambia el chat si no tocas la config.

## Qué hace

Con `AI_CHAT_VOICE=true` aparece un botón de micrófono en el formulario del widget. La persona habla, revisa el texto en el textarea y envía cuando quiere.

- Un solo driver a la vez, elegido con `AI_CHAT_VOICE_DRIVER`. No hay fallback automático de uno a otro.
- `browser`: Web Speech API en el navegador. La app no recibe audio ni gasta API.
- `laravel-ai`: el navegador graba y PHP transcribe con `Laravel\Ai\Transcription`.
- `xai`: el mismo audio, con un `POST` del paquete a `https://api.x.ai/v1/stt`. `laravel/ai` no transcribe con xAI.
- El texto se **concatena** a lo que ya estaba escrito. No borra el tipeo.
- `AI_CHAT_VOICE_AUTO_SUBMIT=true` envía solo si el dictado no quedó vacío. El default es `false`.
- La API key no se renderiza en Blade, ni en el JSON de Alpine, ni en los mensajes de error.

## Qué no hace

- No añade métodos a `ChatDriver`. El dictado no pasa por `ask()` hasta que la persona envía el texto (o `auto_submit` está en true).
- No migra tablas ni guarda el audio en `ai_chat_*`.
- No transcodifica. No hay ffmpeg. Si un provider rechaza WebM, se cambia de provider o de driver.
- No abre el WebSocket de xAI. No hay dictado token a token contra Grok.
- No enciende un segundo motor en paralelo. Cambiar de driver es un cambio de config.
- Un nombre de driver desconocido no cae en `browser`. El micrófono no se muestra.

## Requisitos

- Lo que ya pide el paquete: PHP 8.3+, Laravel 12 o 13, Livewire 4, `laravel/ai` ^1.0.
- `browser` no necesita key.
- `laravel-ai` necesita un provider de `laravel/ai` que sepa transcribir y su API key en `config/ai.php`.
- `xai` necesita `XAI_API_KEY` (o la misma key ya puesta en `config/ai.php` de `laravel/ai`).
- El navegador tiene que poder usar el micrófono. `getUserMedia` y Web Speech exigen un contexto seguro: HTTPS, o `localhost`. Un `http://` en una IP de la red no alcanza.

## Instalación nueva

1. Instala el widget como en [INTEGRATION.md](INTEGRATION.md). No hace falta otro comando para el dictado: el botón y el CSS entran con el componente y con `@aiChatStyles`.
2. Deja `AI_CHAT_VOICE` sin definir, o en `false`, hasta que quieras el micrófono.
3. No publiques las vistas si no necesitas cambiar el HTML. `@aiChatStyles` lee el CSS del paquete; no hay build de Vite ni `npm`.

```env
AI_CHAT_VOICE=false
AI_CHAT_VOICE_DRIVER=browser
AI_CHAT_VOICE_LANGUAGE=es-CL
```

`browser` es el default del driver para que, el día que alguien encienda solo el flag, no empiece a subir audio ni a gastar API. Encender un driver de pago es una segunda decisión (`laravel-ai` o `xai`).

## Actualizar una app que ya está en producción

1. `composer update cbstian/laravel-ai-chat-widget`. Sin `migrate`. Sin `ai-chat:install`. No se toca `app/Ai`.
2. Si **no** publicaste `config/ai-chat.php`, no copies nada. `enabled` se lee con default `false` y el micrófono no aparece.
3. Si **sí** publicaste `config/ai-chat.php` antes de esta versión, ese archivo reemplaza al del paquete y no trae `voice`. Hasta que copies el bloque, el micrófono sigue oculto. Añade esto al array publicado:

```php
'voice' => [
    'enabled' => filter_var(env('AI_CHAT_VOICE', false), FILTER_VALIDATE_BOOLEAN),
    'driver' => env('AI_CHAT_VOICE_DRIVER', 'browser'),
    'language' => env('AI_CHAT_VOICE_LANGUAGE', 'es-CL'),
    'auto_submit' => filter_var(env('AI_CHAT_VOICE_AUTO_SUBMIT', false), FILTER_VALIDATE_BOOLEAN),
    // null: todos los paneles. Lista de ids de Filament. Fuera de Filament no aplica.
    'panels' => null,
    'max_seconds' => (int) env('AI_CHAT_VOICE_MAX_SECONDS', 60),
    'max_kilobytes' => (int) env('AI_CHAT_VOICE_MAX_KB', 2048),
    'rate_limit_per_minute' => (int) env('AI_CHAT_VOICE_RATE_LIMIT', 10),
    'browser' => [
        'interim_results' => true,
        'continuous' => false,
    ],
    'laravel-ai' => [
        'provider' => env('AI_CHAT_VOICE_PROVIDER'),
        'model' => env('AI_CHAT_VOICE_MODEL'),
        'diarize' => false,
    ],
    'xai' => [
        'key' => env('XAI_API_KEY'),
        'url' => env('AI_CHAT_XAI_STT_URL', 'https://api.x.ai/v1/stt'),
        'model' => env('AI_CHAT_XAI_STT_MODEL', 'grok-voice-transcribe-2.0'),
        'format' => filter_var(env('AI_CHAT_XAI_STT_FORMAT', true), FILTER_VALIDATE_BOOLEAN),
        'filler_words' => false,
        'keyterms' => [],
        'timeout' => 30,
    ],
],
```

No pegues la API key dentro de ese PHP si el archivo se commitea. `env('XAI_API_KEY')` y el `.env` alcanzan.

4. Vistas publicadas. Si alguna vez corriste `php artisan vendor:publish --tag=ai-chat-views`, Laravel usa `resources/views/vendor/ai-chat/` y **no** el Blade nuevo del paquete. Ahí tienen que estar `livewire/widget.blade.php` y `livewire/voice-script.blade.php`. Puedes volver a publicar con `--force` (pisa tus cambios) o copiar esos dos archivos del paquete y reaplicar lo que hubieras editado. Si no publicaste vistas, no hagas nada.
5. CSS. `@aiChatStyles` incrusta `resources/css/ai-chat.css` del paquete en cada request. No hay que recompilar assets. Si copiaste el CSS a mano en un layout, súmale las reglas `.pc-ai-chat__mic`. Publicar `ai-chat-assets` copia el CSS a `public/vendor/ai-chat`, pero el widget no lo enlaza: sigue valiendo la directiva.
6. `php artisan config:clear` si la config está cacheada.

La subida de `laravel-ai` y `xai` es la acción Livewire `transcribe` del mismo componente, con el mismo usuario que `ask()`. No se añade una route del paquete, para no chocar con el guard de Filament.

Livewire guarda el audio en su disco temporal (por defecto local, `livewire-tmp`) y el paquete lo borra al terminar, también si la transcripción falla. El disco temporal tiene que ser local: el driver lee un path en disco. El tope de Livewire por defecto (12 MB) es mayor que el del paquete (2 MB). Si en `config/livewire.php` restringiste `temporary_file_upload.rules` a imágenes, el audio no entra: deja `file` y un `max` de al menos 2048 KB.

## Variables por driver

Comunes:

| Variable | Default | Efecto |
|---|---|---|
| `AI_CHAT_VOICE` | `false` | `false` gana aunque el driver esté puesto. Sin micrófono |
| `AI_CHAT_VOICE_DRIVER` | `browser` | `browser`, `laravel-ai` o `xai` |
| `AI_CHAT_VOICE_LANGUAGE` | `es-CL` | BCP 47. Ver el mapeo más abajo |
| `AI_CHAT_VOICE_AUTO_SUBMIT` | `false` | `true` llama a `submit()` si el texto no quedó vacío |
| `AI_CHAT_VOICE_MAX_SECONDS` | `60` | El navegador corta la grabación |
| `AI_CHAT_VOICE_MAX_KB` | `2048` | El servidor rechaza por tamaño. No usa la duración que declare el JS |
| `AI_CHAT_VOICE_RATE_LIMIT` | `10` | Transcripciones por minuto y por id de usuario (`ai-chat:voice:{id}`), aparte de los 20 mensajes del chat |

Si la voz está encendida, el micrófono se muestra siempre. La persona no puede ocultarlo. La única forma de quitarlo en una pantalla es la prop del widget, que es una decisión de quien integra la app, no un ajuste del usuario:

```blade
@livewire('ai-chat-widget', ['voice' => false])
```

Esa prop está bloqueada y solo puede forzar el apagado. No es el mismo mecanismo que el botón «Ocultar» que hubo un momento (eso guardaba `pc-ai-chat-voice` en `localStorage`). Ese botón ya no existe. Si el navegador todavía tiene esa clave, el widget la borra al abrir el panel.

### `browser`

```env
AI_CHAT_VOICE=true
AI_CHAT_VOICE_DRIVER=browser
AI_CHAT_VOICE_LANGUAGE=es-CL
```

No hay API key. Una toma (`continuous` false). Si `browser.interim_results` es true, los interinos se ven en el textarea y el resultado final los reemplaza. El audio no se sube a tu app.

En Chrome el audio lo procesa el servicio de Google; en Edge, el de Microsoft; en Safari, el de Apple. Firefox de escritorio no trae Web Speech: el botón no se muestra. No es un fallo de PHP.

### `laravel-ai`

```env
AI_CHAT_VOICE=true
AI_CHAT_VOICE_DRIVER=laravel-ai
AI_CHAT_VOICE_LANGUAGE=es-CL
AI_CHAT_VOICE_PROVIDER=openai
AI_CHAT_VOICE_MODEL=
OPENAI_API_KEY=sk-...
```

`AI_CHAT_VOICE_MODEL` vacío usa el modelo de transcripción por defecto de ese provider. El idioma que viaja es el subtag primario (`es-CL` → `es`). `diarize` queda en false.

Providers que el SDK transcribe, y la variable que lee `config/ai.php`:

| `AI_CHAT_VOICE_PROVIDER` | Variable |
|---|---|
| `openai` | `OPENAI_API_KEY` |
| `groq` | `GROQ_API_KEY` |
| `gemini` | `GEMINI_API_KEY` |
| `mistral` | `MISTRAL_API_KEY` |
| `eleven` | `ELEVENLABS_API_KEY` |
| `openrouter` | `OPENROUTER_API_KEY` |
| `openai-compatible` | `OPENAI_COMPATIBLE_URL` y `OPENAI_COMPATIBLE_API_KEY` |

No uses `xai` ni `anthropic` aquí: el driver lo rechaza y no llama a la red. Si omites `AI_CHAT_VOICE_PROVIDER`, solo funciona cuando `config('ai.default')` es uno de la tabla. Si ese default es `xai` o `anthropic`, el panel dice que configures `voice.laravel-ai.provider` y no hay request.

El audio se envía al provider elegido, no se queda en la app.

### `xai`

```env
AI_CHAT_VOICE=true
AI_CHAT_VOICE_DRIVER=xai
AI_CHAT_VOICE_LANGUAGE=es-CL
XAI_API_KEY=xai-...
```

Opcional: `AI_CHAT_XAI_STT_MODEL` (default `grok-voice-transcribe-2.0`) y `AI_CHAT_XAI_STT_URL` (default `https://api.x.ai/v1/stt`, solo `https`). Si `voice.xai.key` está vacío, se reutiliza `config('ai.providers.xai.key')`.

`es-CL` y `es-MX` se mandan como `es`. `es-ES` se deja si lo configuraste. `format=true` no se envía si no hay idioma. `keyterms` es un array en el config (no en el env): como máximo 100 términos de 50 caracteres.

El audio se envía a xAI. El cuerpo de un error upstream no se muestra a la persona.

## Idioma

| Driver | Qué se envía |
|---|---|
| `browser` | El valor tal cual (`es-CL`). Si lo dejas vacío, el navegador usa el `lang` del HTML |
| `laravel-ai` | Subtag primario ISO 639-1 (`es-CL` y `es-ES` → `es`) |
| `xai` | `es-CL` / `es-MX` / otro `es-*` → `es`. `es-ES` se conserva. El resto usa el subtag primario |

## Apagarlo en un widget

La config manda. La pantalla solo puede forzar el apagado:

```blade
@aiChatStyles
@livewire('ai-chat-widget', ['voice' => false])
```

`voice => true` no enciende el micrófono si `AI_CHAT_VOICE` es false. La prop está bloqueada.

**Filament.** `voice.panels` es `null` (sin filtro) o una lista de ids de panel. Fuera de Filament la lista no aplica. El hook `AI_CHAT_FILAMENT_HOOK=true` no pasa la prop `voice`, así que heredan la config y el filtro de paneles. Para apagar una pantalla concreta, monta el widget a mano con `['voice' => false]`. No combines el hook automático y un montaje manual.

## Otro driver

```php
app(\Cbstian\AiChat\Speech\SpeechToTextManager::class)->extend(
    'acme',
    fn () => new \App\Speech\AcmeSpeechToTextDriver,
);
```

La clase implementa `Cbstian\AiChat\Contracts\SpeechToTextDriver` (`runsInBrowser()` y `transcribe()`). Esta versión no ofrece un gancho JS para un driver de navegador propio: `extend()` está pensado para un driver de servidor. `AI_CHAT_VOICE_DRIVER=acme`.

## Privacidad y límites

| Driver | A dónde va el audio |
|---|---|
| `browser` | Al servicio del navegador (Google, Microsoft o Apple). No a tu servidor |
| `laravel-ai` | Al provider de `AI_CHAT_VOICE_PROVIDER` |
| `xai` | A `https://api.x.ai/v1/stt` |

En los drivers de servidor el navegador graba con `MediaRecorder` (WebM/Opus, WebM o MP4, el primero que el navegador acepte). El blob se sube por Livewire. El archivo temporal se borra en un `finally`, también si la transcripción lanza. No se copia a `storage` permanente ni a los logs del chat.

Topes: 60 segundos, 2 MB, 10 transcripciones por minuto y por usuario. El servidor rechaza por tamaño y por MIME (`audio/webm`, `audio/ogg`, `audio/mp4`, `audio/mpeg`, `audio/wav`, `audio/x-m4a`, `audio/aac`, `audio/flac` y `video/webm`), no por la duración que mande el JS.

El DOM solo recibe el modo (`browser` o `upload`), el idioma del navegador, los segundos, `auto_submit` y los MIME. No recibe el nombre del provider, el modelo ni la key. `auto_submit` no se puede activar desde el request.

## Verificación

1. Con `AI_CHAT_VOICE` ausente o `false`, el panel abierto no tiene botón de micrófono ni `SpeechRecognition` en el HTML.
2. Con `true` y `browser`, en Chrome o Edge, el botón está, pide permiso y el texto queda en el textarea sin enviarse.
3. `voice => false` en el `@livewire` oculta el botón aunque la config esté en true.
4. Un `config/ai-chat.php` viejo, sin bloque `voice`, tampoco muestra el botón.
5. `laravel-ai` con `AI_CHAT_VOICE_PROVIDER=openai` y `OPENAI_API_KEY` real: pulsar, hablar, pulsar otra vez (o esperar el corte) muestra «Transcribiendo…» y concatena el texto.
6. `xai` con `XAI_API_KEY`: igual, y el HTML de la página no contiene la key ni `api.x.ai`.
7. Un archivo que no es audio, o mayor de 2 MB, no llama al provider. El temporal no se queda en `livewire-tmp`.
8. El undécimo dictado en un minuto responde que se alcanzó el límite, sin request nuevo.

En el workbench del paquete, edita `workbench/.env` y arranca con `composer serve`. Ese comando copia `workbench/.env` al skeleton que Testbench realmente ejecuta (`vendor/orchestra/testbench-core/laravel/.env`). Si el skeleton ya existía, un cambio solo en `workbench/.env` no llegaba al servidor: el driver se quedaba en `browser` y una `XAI_API_KEY` mala no se notaba, porque Web Speech no la usa. La página de demo muestra el driver que resolvió el servidor. Para `xai` tiene que decir `xai` y «sube el audio». Si dice `browser`, el proceso no leyó el `.env` nuevo: para el servidor y vuelve a correr `composer serve`. No hace falta `config:clear` salvo que hayas cacheado la config a mano.

## Si no aparece el micrófono o falla el dictado

| Síntoma | Qué mirar |
|---|---|
| No hay botón tras el update | Esperable con el default. Falta `AI_CHAT_VOICE=true`, o el config publicado no tiene `voice` |
| `config('ai-chat.voice.enabled')` sigue false | `php artisan config:clear` |
| El flag está en true y el driver es un nombre raro | El aviso dice que el driver configurado no está disponible. No se disfraza de Web Speech |
| El HTML no tiene botón y publicaste las vistas | Falta el Blade nuevo en `resources/views/vendor/ai-chat/livewire/` |
| El botón no se ve en Firefox con `browser` | Firefox de escritorio no tiene Web Speech. Usa `laravel-ai` o `xai` |
| El botón no se ve en un Safari viejo con driver de servidor | Sin `MediaRecorder` el botón se oculta en el cliente |
| El navegador no pide permiso, o `getUserMedia` falla | La página no es HTTPS ni `localhost` |
| Permiso denegado | El panel lo dice y no reintenta solo. Hay que permitirlo en el sitio |
| «No se pudo transcribir» con `xai` | Key ausente, 401 o timeout (30 s). El mensaje no incluye el body ni la key. Revisa `XAI_API_KEY` |
| «Demasiadas transcripciones» | 429 de xAI, o las 10 por minuto del paquete |
| «El provider […] no transcribe audio» | `AI_CHAT_VOICE_PROVIDER` es `xai`, `anthropic` u otro sin STT |
| «Configura voice.laravel-ai.provider…» | El provider está vacío y `ai.default` no transcribe |
| El upload ni llega a PHP | `temporary_file_upload.rules` de Livewire no acepta audio, o el disco temporal no es local |
| El texto no se envía solo | `AI_CHAT_VOICE_AUTO_SUBMIT` es false. Es el default |
| Quieres el WebSocket de xAI | No está. El flujo es pulsar, hablar, soltar y revisar |

## Límites

El contrato público del driver es `runsInBrowser()` y `transcribe()`. Un método nuevo ahí rompería un driver que la app haya registrado. El WebSocket de xAI, si alguna vez se hace, sería otra fase: proxy en PHP y la key solo en el servidor.
