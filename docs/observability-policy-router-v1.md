# Observability Policy Router v1

Slice puro de #56. Conecta un `Incident v1` ya correlacionado con contratos existentes; no envía notificaciones, no persiste y no ejecuta freezes.

## Límite

`Incident + Policy + Evidence → OwnerInbox + PushNotification? + PauseState?`

- `OwnerInbox` sigue siendo la superficie canónica para el owner.
- `ExternalApiPushNotification` define payload, detalle autenticado y dedupe; el router no entrega a un provider.
- `PauseControl` define el freeze. El router solo proyecta `state=unknown`: bloquea mutaciones fail-closed sin afirmar activación que no ocurrió.
- `PauseProductionGate` demuestra que esa intención bloquea writes y conserva reads tipadas.
- `stale|unknown` nunca puede originar freeze automático.
- La policy debe ser versionada y el scope de proyecto debe coincidir con el Incident.
- La policy declara explícitamente la clase de inbox y los canales por severidad; cualquier drift del mapa canónico falla cerrado.
- El router revalida invariantes que distinguen un Incident realmente emitido por `ObservabilityIncident::correlate()` de un envelope sintáctico fabricado.

## Decisión

Se eligió un bridge determinista en vez de otro alert manager. Así #56 reutiliza autoridades ya integradas y conserva una única semántica de inbox, push y pause.

No hay DB, red, filesystem write, shell, provider, scheduler mutation ni clock global.
