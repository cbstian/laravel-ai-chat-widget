# Historial compactado

Guía para incorporar o actualizar la compactación de historial de `cbstian/laravel-ai-chat-widget` en una app Laravel. El flujo general del paquete sigue en [INTEGRATION.md](INTEGRATION.md).

Está **apagado por defecto**. Un `composer update` no cambia lo que el modelo recibe si no tocas la config. Un `config/ai-chat.php` publicado antes de esta clave tampoco: el código lee `config('ai-chat.history')` y, si no existe, `enabled` queda en `false`.

## Qué problema resuelve

`laravel/ai` guarda cada tool result completo en `agent_conversation_messages.steps`. En el turno siguiente, `DatabaseConversationStore::getLatestConversationMessages` (tope 100 mensajes) lo reconstruye como `AssistantMessage` más `ToolResultMessage` y lo vuelve a enviar. Una guía de 8.000 caracteres viaja en cada request.

Además, un modelo puede emitir un preámbulo en inglés (`I'll check that for you.`) como `TextDelta` antes del `ToolCall`. `TextDelta::combine` lo deja pegado en `content`. Aparece al recargar y se reenvía al modelo.

Con `AI_CHAT_HISTORY=true` el paquete envuelve el `ConversationStore` y, **solo al leer** hacia el proveedor:

- Sustituye el tool result de los turnos de asistente viejos por un stub en español: nombre, tamaño original, argumentos recortados, y la pista de volver a llamar la tool.
- Deja enteros los últimos `keep_full_tool_results_for_turns` turnos de asistente (default 1).
- Puede recortar también ese turno reciente si `max_tool_result_chars` es mayor que 0. El corte lleva `… [recorte]`.
- Si `token_budget` es mayor que 0 y la estimación se pasa, compacta tool results que aún iban enteros (del más viejo al más nuevo) y después quita pares usuario/asistente desde el más viejo.
- Con `hide_pre_tool_text` (default true, y solo si `enabled`), el `AssistantMessage` de un step con tool calls viaja con content vacío. El step final, sin tools, conserva la respuesta.
- Las burbujas al recargar usan el `content` de los steps que no tienen tool calls. Si la fila no tiene `steps`, se usa la columna `content`.
- En el streaming, ese preámbulo no se pinta si llega un tool call antes de soltar el buffer.

`AI_CHAT_FORCE_LANGUAGE=español` añade, solo durante `ask()` del driver, esta línea al final de las instrucciones:

`Responde siempre en español, también el texto que emites antes de usar una herramienta.`

## Qué no hace

- No escribe ni modifica `steps` ni `content`. Apagar la opción devuelve el replay completo. No hay migración.
- No compacta el turno que se está generando. Esos tool results viven en el loop de `laravel/ai` y no pasan por el store.
- No quita un `tool_call` sin su mensaje `tool`. Conserva `id`, nombre, argumentos y `resultId`. DeepSeek usa `resultId ?? id` como `tool_call_id`.
- No toca otras conversaciones de la app si `only_chat_sessions` sigue en true (default). Solo entra un id que esté en `ai_chat_sessions.agent_conversation_id`.
- No añade métodos a `ChatDriver`.
- No usa el tokenizer del provider. El presupuesto es `ceil(caracteres / 4)`.
- Si el presupuesto no alcanza ni después de compactar y de quitar pares viejos, el último par se envía igual.

## Requisitos

Lo que ya pide el paquete: PHP 8.3+, Laravel 12 o 13, Livewire 4, `laravel/ai` ^1.0. No hay dependencia nueva. Hace falta la tabla `ai_chat_sessions` si `only_chat_sessions` sigue en true; si no existe, esa conversación no se compacta.

## Instalación nueva

1. Instala el widget como en [INTEGRATION.md](INTEGRATION.md). No hay comando ni migración para el historial.
2. Deja `AI_CHAT_HISTORY` sin definir, o en `false`, hasta que quieras compactar.
3. Si no publicaste `config/ai-chat.php`, el bloque del paquete ya lee estos env.

```env
AI_CHAT_HISTORY=false
```

## Actualizar una app que ya publicó `config/ai-chat.php`

`mergeConfigFrom` no rellena claves nuevas dentro de un array que la app ya definió. Un `config:cache` anterior tampoco las trae hasta volver a cachear. Sin el bloque `history`, el proveedor sigue recibiendo el tool result entero aunque pongas `AI_CHAT_HISTORY` en `.env`.

1. `composer update cbstian/laravel-ai-chat-widget`. Sin `migrate`. Sin `ai-chat:install`.
2. Copia este bloque al array publicado:

```php
'history' => [
    'enabled' => filter_var(env('AI_CHAT_HISTORY', false), FILTER_VALIDATE_BOOLEAN),
    'only_chat_sessions' => filter_var(env('AI_CHAT_HISTORY_ONLY_CHAT_SESSIONS', true), FILTER_VALIDATE_BOOLEAN),
    'keep_full_tool_results_for_turns' => (int) env('AI_CHAT_HISTORY_KEEP_FULL_TURNS', 1),
    'compact_tool_result_chars' => (int) env('AI_CHAT_HISTORY_COMPACT_CHARS', 240),
    'max_tool_result_chars' => (int) env('AI_CHAT_HISTORY_MAX_TOOL_RESULT_CHARS', 0),
    'token_budget' => (int) env('AI_CHAT_HISTORY_TOKEN_BUDGET', 0),
    'hide_pre_tool_text' => filter_var(env('AI_CHAT_HIDE_PRE_TOOL_TEXT', true), FILTER_VALIDATE_BOOLEAN),
    'force_language' => env('AI_CHAT_FORCE_LANGUAGE') ?: null,
],
```

3. En `.env`:

```env
AI_CHAT_HISTORY=true
```

4. Si usas `config:cache`, vuelve a correr `php artisan config:cache`.

`AI_CHAT_HISTORY_ONLY_CHAT_SESSIONS` default `true`. Ponlo en `false` solo si este proceso no tiene otros agentes que compartan el mismo `ConversationStore`.

## Claves

| Clave | Env | Default | Efecto |
|---|---|---|---|
| `enabled` | `AI_CHAT_HISTORY` | `false` | Apagado: el store no se envuelve |
| `only_chat_sessions` | `AI_CHAT_HISTORY_ONLY_CHAT_SESSIONS` | `true` | Fuera de `ai_chat_sessions`, el historial no se toca |
| `keep_full_tool_results_for_turns` | `AI_CHAT_HISTORY_KEEP_FULL_TURNS` | `1` | Turnos de asistente, desde el final, con result entero |
| `compact_tool_result_chars` | `AI_CHAT_HISTORY_COMPACT_CHARS` | `240` | Tope del stub, en caracteres |
| `max_tool_result_chars` | `AI_CHAT_HISTORY_MAX_TOOL_RESULT_CHARS` | `0` | `0` no recorta el turno reciente |
| `token_budget` | `AI_CHAT_HISTORY_TOKEN_BUDGET` | `0` | `0` apagado. Estimación, no tokens del provider |
| `hide_pre_tool_text` | `AI_CHAT_HIDE_PRE_TOOL_TEXT` | `true` | Solo aplica si `enabled` es true |
| `force_language` | `AI_CHAT_FORCE_LANGUAGE` | `null` | Vacío o ausente no toca las instrucciones |

Un valor vacío, `false`, `0` o `"false"` en `enabled` deja la función apagada. `hide_pre_tool_text` en false, con la compactación prendida, reenvía y muestra el preámbulo.

## Cómo verificar que funciona

1. Enciende `AI_CHAT_HISTORY` y manda un turno que llame una tool con un texto largo (una guía, un listado).
2. Mira la fila en `agent_conversation_messages`: `steps` sigue con el result entero. La base no cambia.
3. Manda un segundo mensaje. En el request al provider (log del cliente HTTP, o el payload que arme el gateway) el tool result viejo es un texto que empieza por `Resultado compactado de`. El `tool_call_id` coincide con el `id` del tool call. El turno más reciente sigue entero.
4. Recarga la página. La burbuja del asistente no incluye el preámbulo en inglés; queda la respuesta del step que no llamó tools.
5. En vivo, un preámbulo corto seguido de un tool call no se pinta. Aparece la etiqueta en español (`ai-chat.tool_labels`) y, en el step siguiente, la respuesta se streamea.

Apagar `AI_CHAT_HISTORY` y repetir el segundo turno devuelve el result completo. No hay que migrar ni borrar filas.

## Preámbulo en pantalla

`laravel/ai` 1.x no emite un fin de step. `TextDelta::combine` corta los steps por el `StreamStart` siguiente, y `StreamEnd` cierra el turno. El widget usa esa misma señal:

- Guarda los `TextDelta` hasta saber si el step va a llamar una tool.
- Suelta el texto al llegar a 160 caracteres, al haber un párrafo (`\n\n`), al `StreamStart` siguiente sin tool, o al `StreamEnd`.
- Un `ToolCall` antes de eso tira el buffer y muestra la etiqueta. El step de la respuesta final se streamea desde el primer delta que se suelta; no se espera al final del turno.
- Un preámbulo de más de 160 caracteres, o con un párrafo, puede alcanzar a pintarse. Si después llega el tool call, la etiqueta reemplaza lo que ya se mostró.

## Idioma y presupuesto

`force_language` no envuelve el agente. `AgentPrompt::$agent` es readonly y la fila guarda `$agent::class`. El driver, solo durante `ask()`, pone un gateway delante del text gateway del provider de `AI_CHAT_PROVIDER` y lo restaura al salir. Un failover a otro provider no lleva esa línea. Con `FakeChatAgent::fake()` el SDK clona el provider, así que el sufijo no se ve en la suite falsa; en un provider real sí entra en las instrucciones.

`token_budget` cuenta caracteres del content, de los argumentos del tool call y del texto del tool result, y divide por 4. No es el tokenizer de DeepSeek, OpenAI ni de otro provider. Sirve como tope grueso. El default `0` lo deja apagado a propósito.

## Cómo se engancha

Cuando la app ya arrancó y `history.enabled` es true, el provider hace `extend` de `Laravel\Ai\Contracts\ConversationStore` con `Cbstian\AiChat\History\CompactingConversationStore`. `getLatestConversationMessages` delega y pasa la colección a `ToolResultCompactor`. Guardar, approvals, paginar y verificar al dueño se delegan tal cual.

Los `ToolResult` y el `content` del `AssistantMessage` se reemplazan en copias. La fila sigue igual.

## Límites de laravel/ai

- El turno en curso no pasa por el store. Sus tool results no se compactan.
- El tope de 100 mensajes (`maxConversationMessages`) sigue siendo el de `laravel/ai`. Esta opción recorta dentro de lo que ese método ya devolvió.
- En un turno `completed`, `replay_blocks` suele ir vacío. Un turno en pausa por approval puede traerlos; el compactor no los borra.
- `force_language` solo cubre el provider configurado en `AI_CHAT_PROVIDER`.

## Probar la rama antes de que entre a master

En el `composer.json` de la app:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/cbstian/laravel-ai-chat-widget"
    }
]
```

```bash
composer require cbstian/laravel-ai-chat-widget:dev-cursor/history-compaction
```

Si Composer no baja el commit nuevo: `composer clear-cache` y `composer update cbstian/laravel-ai-chat-widget`. Después copia el bloque `history` si el config ya estaba publicado, pon `AI_CHAT_HISTORY=true` y, si hace falta, `php artisan config:cache`.

En el workbench del paquete: `AI_CHAT_HISTORY=true` en `workbench/.env` y `composer serve` (ese comando copia el `.env`). La página dice si quedó prendido. El agente de demo no ejecuta tools de negocio; el stub se ve en una app cuyas tools devuelven texto largo.

## Riesgos

- El stub pierde el detalle. Si el modelo lo necesita, tiene que volver a llamar la tool.
- `only_chat_sessions` en false compacta cualquier conversación que pase por el mismo store.
- `token_budget` puede dejar el último par aunque supere la cifra. No parte un tool call de su result.
- `max_tool_result_chars` corta por el final. Un JSON puede quedar inválido. El default 0 evita eso en el turno reciente.
- Si la tabla `ai_chat_sessions` no existe, esa lectura se trata como «no es conversación del widget» y no se compacta.
