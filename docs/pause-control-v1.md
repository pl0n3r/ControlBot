# PauseControl v1

\`PauseControl\` completa el contrato de pausas del Agent Runtime sin crear un scheduler, una base de datos ni una autoridad paralela.

## Límite del componente

Entrada: un contexto Runtime (\`session_id\`, \`account_id\`, \`project_id\`) y cero o más \`PauseState\` v1. Salida: una decisión determinista de bloqueo para mutaciones. El componente no persiste, no consulta red y no ejecuta providers ni FactoryRunner.

## PauseState v1

Campos cerrados: \`version\`, \`pause_id\`, \`scope_type\`, \`scope_id\`, \`state\`, \`reason\`, \`source\`, \`created_at\`, \`activated_at\`, \`released_at\`, \`preemptibility\`, \`safe_point_at\`, \`policy_version\`, \`incident_id\`, \`evidence_ref\`.

Scopes: \`session | account | project | global\`. Estados: \`active | releasing | released | unknown\`. Precedencia efectiva: \`global > project > account > session\`.

Un scope que no coincide exactamente con el contexto evaluado falla cerrado. \`unknown\` también bloquea mutaciones: ausencia de evidencia nunca equivale a permiso.

## Preemption y safe points

- \`immediate\`: puede estar activo sin \`safe_point_at\` y no puede inventar uno.
- \`safe_point\`: un estado active/releasing/released exige evidencia temporal de safe point previa o igual a \`activated_at\`.
- \`non_preemptible\`: usa la misma exigencia; antes de esa evidencia no puede declararse \`active\`.

El contrato no mata procesos ni reencola WorkItems. Esas acciones pertenecen a integración posterior con Scheduler/FactoryRunner.

## Release

\`release()\` acepta únicamente estados conocidos y conserva el mismo schema. Una segunda liberación devuelve el mismo estado \`released\`, por lo que la operación es idempotente. Campos extra como capabilities, approvals, budget o authority son rechazados por schema cerrado.

## Freeze automático de proyecto

Un \`scope_type=project\` con \`source=policy\` requiere simultáneamente \`policy_version\`, \`incident_id\` y \`evidence_ref\`. La ausencia de cualquiera invalida el estado; no existe freeze automático autocertificado.

## Seguridad y reversión

El módulo acepta solo identificadores/texto acotados y rechaza material sensible obvio en \`reason\`. No contiene I/O externo, secretos, credenciales ni side effects. Revertir el archivo y sus escenarios restaura el Runtime previo sin migraciones ni cambios de datos.

## Fuera de alcance

Persistencia, endpoints, UI, observabilidad, reencolado, notificaciones, provider writes y enforcement de Production Authority.
