# GlobalSearchIndex v1

`GlobalSearchIndex` añade estado incremental puro sobre `GlobalSearchCore`; no persiste ni consulta proveedores.

## Contrato

El estado mantiene `watermarks` por source y entidades por `source|source_identity`. Cada watermark guarda el fingerprint del batch: watermark menor falla, el mismo con batch distinto es conflicto y el mismo batch es retry idempotente.

`upsert` reemplaza el documento conservando identidad estable, por lo que rename/move no duplica. `delete` deja tombstone. Cambios y documentos se canonicalizan antes del fingerprint y un batch nunca modifica otra fuente.

## Consulta y límites

Los documentos se validan con `GlobalSearchCore` y `search()` delega al core existente, preservando access, freshness, filtros, ranking, sanitización y paginación.

El fixture ejecutable cubre reindex equivalente y un benchmark local de 200 entidades con p95 <2 s. MariaDB/FTS, migrations, webhooks, polling, UI y persistencia durable quedan fuera.
