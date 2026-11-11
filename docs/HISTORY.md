# Historial compactado

Guía para activar la compactación del historial que `cbstian/laravel-ai-chat-widget` reenvía al proveedor. El flujo general del paquete sigue en [INTEGRATION.md](INTEGRATION.md).

Está **apagado por defecto**. Un `composer update` no cambia lo que el modelo recibe si no tocas la config. Un `config/ai-chat.php` publicado antes de esta clave tampoco: el código lee `config('ai-chat.history')` con default `enabled = false`.

## Qué hace

`laravel/ai` guarda cada tool result completo en `agent_conversation_messages.steps` y, al armar el turno siguiente, lo vuelve a poner en el request (`DatabaseConversationStore::getLatestConversationMessages`, tope 100 mensajes). Una guía de varios miles de caracteres viaja en cada turno.

Con `AI_CHAT_HISTORY=true` el paquete envuelve el `ConversationStore` y, **solo al leer** hacia el proveedor:

- Sustituye el tool result de los turnos de asistente viejos por un stub en español (nombre, tamaño original, argumentos recortados, y la pista de volver a llamar la tool).
- Deja enteros los últimos `keep_full_tool_results_for_turns` turnos de asistente (default 1).
- Puede recortar también ese turno reciente si `max_tool_result_chars` es mayor que 0.
- Si `token_budget` es mayor que 0 y la estimación chars/4 se pasa, compacta tool results que aún iban enteros (del más viejo al más nuevo) y después quita pares usuario/asistente desde el más viejo.
- Con `hide_pre_tool_text` (default true), el texto que el modelo emitió antes de un tool call no se reenvía: el `AssistantMessage` de ese step viaja con content vacío. El step final, sin tools, conserva la respuesta.
- Las burbujas al recargar se arman con el `content` de los steps que no tienen tool calls. Si la fila no tiene `steps`, se usa la columna `content`.
- En el streaming del widget, ese preámbulo no se pinta si llega un tool call antes de soltar el buffer.

`AI_CHAT_FORCE_LANGUAGE=español` (u otro valor) añade al final de las instrucciones, solo durante `ask()` del driver:

`Responde siempre en español, también el texto que emites antes de usar una herramienta.`

## Qué no hace

- No escribe ni modifica `steps` ni `content`. Apagar la opción devuelve el replay completo. No hay migración.
- No compacta el turno que se está generando: esos tool results viven en el loop de `laravel/ai`, no pasan por el store.
- No quita un `tool_call` sin su mensaje `tool`. Conserva `id`, nombre, argumentos y `resultId`, que es el `tool_call_id` que DeepSeek exige.
- No toca otras conversaciones de la app si `only_chat_sessions` sigue en true (default). Solo entra un id que esté en `ai_chat_sessions.agent_conversation_id`.
- No añade métodos a `ChatDriver`.
- No cuenta tokens del tokenizer del provider. La cifra es `ceil(caracteres / 4)`.
- Si el presupuesto no alcanza ni después de compactar y de quitar pares viejos, el último par se envía igual.

## Activar

```env
AI_CHAT_HISTORY=true
AI_CHAT_HISTORY_KEEP_FULL_TURNS=1
AI_CHAT_HISTORY_COMPACT_CHARS=240
AI_CHAT_HISTORY_MAX_TOOL_RESULT_CHARS=0
AI_CHAT_HISTORY_TOKEN_BUDGET=0
AI_CHAT_HIDE_PRE_TOOL_TEXT=true
AI_CHAT_FORCE_LANGUAGE=
```

`AI_CHAT_HISTORY_ONLY_CHAT_SESSIONS` default `true`. Ponlo en `false` solo si este proceso no tiene otros agentes que compartan el mismo `ConversationStore` y quieras compactar todas las conversaciones.

Quien ya publicó `config/ai-chat.php` tiene que copiar el bloque `history` a mano. `mergeConfigFrom` no rellena claves nuevas dentro de un array que la app ya definió, y un `config:cache` anterior tampoco las trae hasta volver a cachear. Sin la clave, el comportamiento es el de siempre.

En el workbench: `AI_CHAT_HISTORY=true` en `workbench/.env` y `composer serve` (ese comando copia el `.env`). La página dice si quedó prendido. El agente de demo no ejecuta tools de negocio; para ver el stub hace falta una app cuyas tools devuelvan texto largo.

## Claves

| Clave | Env | Default | Efecto |
|---|---|---|---|
| `enabled` | `AI_CHAT_HISTORY` | `false` | Apagado: el store no se envuelve |
| `only_chat_sessions` | `AI_CHAT_HISTORY_ONLY_CHAT_SESSIONS` | `true` | Fuera de `ai_chat_sessions`, el historial no se toca |
| `keep_full_tool_results_for_turns` | `AI_CHAT_HISTORY_KEEP_FULL_TURNS` | `1` | Turnos de asistente, desde el final, con result entero |
| `compact_tool_result_chars` | `AI_CHAT_HISTORY_COMPACT_CHARS` | `240` | Tope del stub |
| `max_tool_result_chars` | `AI_CHAT_HISTORY_MAX_TOOL_RESULT_CHARS` | `0` | `0` no recorta el turno reciente. Si no, corta por el final con `… [recorte]` |
| `token_budget` | `AI_CHAT_HISTORY_TOKEN_BUDGET` | `0` | `0` apagado |
| `hide_pre_tool_text` | `AI_CHAT_HIDE_PRE_TOOL_TEXT` | `true` | Solo si `enabled` |
| `force_language` | `AI_CHAT_FORCE_LANGUAGE` | `null` | Solo si `enabled` y el valor no está vacío |

## Cómo se engancha

El provider, cuando la app ya arrancó y `history.enabled` es true, hace `extend` de `Laravel\Ai\Contracts\ConversationStore` con `Cbstian\AiChat\History\CompactingConversationStore`. `getLatestConversationMessages` delega y pasa la colección a `ToolResultCompactor`. El resto de métodos (guardar, approvals, paginar, dueño) se delegan tal cual.

Los `ToolResult` y el `content` del `AssistantMessage` se reemplazan en copias. La fila de la base sigue igual.

El preámbulo en pantalla usa un buffer. No hay evento de fin de step en `laravel/ai` 1.4: `TextDelta::combine` corta los steps por `StreamStart`, y `StreamEnd` cierra el turno. El buffer se suelta al pasar ~160 caracteres, al haber un párrafo (`\n\n`), al llegar el `StreamStart` siguiente sin tool, o al `StreamEnd`. Un `ToolCall` antes de eso tira el buffer y muestra la etiqueta en español, como hoy. A partir de ahí la respuesta del step siguiente se streamea; no se espera al final del turno para pintar un texto largo.

`force_language` no envuelve el agente: `AgentPrompt::$agent` es readonly y la fila guarda `$agent::class`. El driver, solo durante `ask()`, pone un `LanguageSuffixGateway` delante del text gateway del provider configurado y lo restaura al terminar. `FakeChatAgent::fake()` sustituye ese gateway en un clon, así que el sufijo no se ve en la suite falsa; en un provider real sí entra en las instrucciones.

## Riesgos

- El stub pierde el detalle. Si el modelo lo necesita, tiene que volver a llamar la tool. Eso es a propósito.
- `only_chat_sessions` en false compacta cualquier conversación que pase por el mismo store, también agentes que no son el widget.
- `token_budget` puede dejar el último par aunque supere la cifra. No parte un tool call de su result.
- `max_tool_result_chars` corta por el final. Un JSON queda inválido. El default 0 evita eso en el turno reciente.
- El preámbulo largo (más de ~160 caracteres o con un párrafo) puede alcanzar a pintarse y, si después llega un tool call, la etiqueta lo reemplaza.
- `force_language` se aplica al provider de `AI_CHAT_PROVIDER` durante `ask()`. Un failover a otro provider no lleva el gateway envuelto.
- Si la tabla `ai_chat_sessions` no existe, esa lectura se trata como «no es conversación del widget» y no se compacta.
