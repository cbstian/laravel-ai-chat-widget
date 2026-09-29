# Política de versionado

Este paquete sigue [Semantic Versioning 2.0.0](https://semver.org/lang/es/).

Formato: `MAJOR.MINOR.PATCH` (ej. `1.2.3`).

## Qué significa cada número

| Cambio | Cuándo | Ejemplo |
|--------|--------|----------|
| **MAJOR** (`1.0.0` → `2.0.0`) | Breaking change público: contratos, config con nuevo significado obligatorio, renombre de namespaces/clases públicas, eliminación de APIs | Renombrar `ResolvesChatAgent`, quitar una opción de config sin fallback |
| **MINOR** (`1.0.0` → `1.1.0`) | Funcionalidad nueva compatible hacia atrás | Nuevo hook Filament opcional, nuevo evento, opción de config con default seguro |
| **PATCH** (`1.0.0` → `1.0.1`) | Bugs, docs, tests, hardening sin cambiar API | Fix de CSS, corrección de migración, typo en README |

## Pre-1.0 (`0.x.y`)

Mientras el paquete esté en `0.x`:

- `0.MINOR.PATCH`: la API pública **puede** cambiar entre minors si hace falta estabilizar el diseño.
- Intentaremos documentar breaking changes en el `CHANGELOG.md`, pero la estabilidad fuerte empieza en **`1.0.0`**.

Primer release público previsto: **`0.1.0`**.

## Qué se considera API pública

- Contratos en `src/Contracts/`
- DTOs públicos en `src/Dto/`
- Config publicada (`config/ai-chat.php`) y variables `AI_CHAT_*` documentadas
- Comando `ai-chat:install`
- Componente Livewire `ai-chat-widget` y directiva `@aiChatStyles`
- Esquema de migraciones publicadas (añadir columnas es MINOR; renombrar/eliminar es MAJOR)

**No** es API pública: drivers internos no documentados, stubs de tests, workbench, archivos bajo `docs/` salvo que se indiquen.

## Tags y releases

1. Actualizar `CHANGELOG.md` (mover Unreleased → versión).
2. Commit: `chore(release): versión X.Y.Z`.
3. Tag anotado: `git tag -a vX.Y.Z -m "vX.Y.Z"`.
4. Push: `git push origin main --tags`.
5. Packagist toma el tag automáticamente (webhook).

Composer consume el tag **sin** la `v` en la constraint (`^0.1`, `^1.0`), aunque el tag Git lleve prefijo `v`.

## Compatibilidad Laravel / PHP

| Paquete | Requisitos actuales |
|---------|---------------------|
| PHP | `^8.3` |
| Laravel (illuminate/*) | `^12 || ^13` |
| Livewire | `^4.0` |
| laravel/ai | `^1.0` |

Subir el mínimo de PHP/Laravel de forma incompatible → **MAJOR** (o MINOR en `0.x` con nota clara).

Ampliar soporte a una major nueva de Laravel sin romper la anterior → **MINOR**.

## Branches

- `main`: desarrollo estable / releases.
- No mantenemos branches LTS por ahora; hotfixes van a `main` + tag patch.

## Deprecaciones

Antes de un MAJOR:

1. Marcar como deprecated en docs/CHANGELOG (idealmente un MINOR antes).
2. Mantener el comportamiento viejo un ciclo cuando sea razonable.
3. Eliminar en el siguiente MAJOR.
