# Global Search Core v1

Este slice de #13 es una función pura: recibe documentos ya obtenidos por adapters y devuelve resultados normalizados. No consulta GitHub, no persiste índice y no implementa UI.

## Seguridad y permisos

Cada documento declara \`access=allow|deny|unknown\`; solo \`allow\` entra al matching. \`deny\` y \`unknown\` se descartan antes de ranking o render. Los adapters futuros siguen siendo responsables de verificar el permiso real de la fuente: el índice nunca concede visibilidad.

Título y snippet se minimizan y sanitizan antes de salida. Se redactan credenciales comunes, bearer tokens, email y teléfonos; la navegación conserva \`canonical_url\`. URLs se limitan a recursos \`pl0n3r\` en GitHub o rutas internas relativas y no aceptan query strings con secretos.

## Búsqueda determinista

\`#N\` exige coincidencia exacta del identificador. Texto normal prioriza identificador exacto, título exacto, match en título y luego snippet. Después se ordena por freshness, \`updated_at\` descendente e identidad canónica. Duplicados con la misma \`canonical_url\` colapsan tras ordenar, por lo que gana la mejor evidencia disponible.

Los filtros project/type/state/role se aplican antes del ranking y la paginación ocurre después de dedupe. Permutar los mismos documentos no cambia el resultado ni las páginas.

\`fresh|stale|unavailable\` se conserva literalmente; ningún dato viejo se presenta como fresh por inferencia.

## Siguiente slice

Quedan fuera el índice MariaDB/FTS, watermarks, reindex/rename, adapters reales y el benchmark p95 <2 s (AC-07/AC-08 del parent #13).
