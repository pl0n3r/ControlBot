# External API v1 — threat model de autorización

Este slice implementa únicamente la frontera de autorización de Fase 0 de #128. No implementa login, bearer/refresh tokens, passkeys, MFA, inventario de dispositivos ni revocación. Por defecto, las mutaciones permanecen bloqueadas hasta que exista step-up verificable.

## Trust boundary

```text
request pública
  -> router futuro resuelve operación + resource scope
  -> ExternalApiContract::operation() fija capability/auth_scope
  -> DecisionRuntime + source privada emiten VerifiedAccessContext
  -> ExternalApiAccess
  -> DecisionRights
  -> allow | deny | step_up_required
```

El cliente no aporta identity, role, authority level, policy refs, grants ni capability efectiva. La capability se obtiene del catálogo server-side de #230 y el contexto de autoridad es nominal.

## Amenazas y mitigaciones

### Client-supplied authority
Un array o JSON que imite identity/grant/policy no es un VerifiedAccessContext y no entra al evaluador. La emisión nominal sigue en #122.

### Horizontal scope escalation
El resource scope esperado lo resuelve el servidor. Debe coincidir exactamente con el scope del VerifiedAccessContext; un contexto de otra Venture/Project/Institution devuelve deny.

### Vertical privilege escalation
DecisionRights valida grant, policy, capability e identity. Además, la superficie Owner exige authority level exacto L4_OWNER; un grant L3 válido para otra superficie no basta.

### Confused deputy
El request no selecciona su capability. ExternalApiContract resuelve el operation_id y auth_scope a partir de la ruta conocida por el servidor.

### Replay
Los GET son read-only. La única mutación publicada por #230 permanece step_up_required en este slice; el siguiente contrato de sesión deberá combinar step-up, revocación e idempotencia antes de habilitar ejecución.

### Stolen session
No se afirma mitigación inexistente. Este slice no acuña sesiones móviles. Hasta integrar inventario/revocación y step-up verificable, las mutaciones siguen fail-closed.

### Stale snapshot
Freshness pertenece al contrato de respuesta #230. Un snapshot stale/unknown no eleva authority y no habilita mutaciones en este slice.

### Secret exposure
ExternalApiAccess no recibe ni devuelve provider credentials, master keys, OAuth material ni secretos de infraestructura.

### Direct-execution bypass
La autorización no ejecuta Owner Decisions, WorkItems, FactoryRunner, SSH, SQL ni proveedores. No existe scheduler o queue paralela.

## Límites del TCB

Se confía en DecisionRuntime, VentureAccessSourceContract, VerifiedAccessContext y DecisionRights como frontera server-side existente. Este slice no intenta defender el mismo proceso contra ejecución arbitraria de PHP capaz de reemplazar el TCB.

## Próximo slice

La siguiente etapa debe introducir un contexto nominal de sesión externa de corta vida, inventario de dispositivo/sesión, revocación inmediata y step-up verificable. Solo entonces la mutación approve/reject podrá pasar de step_up_required a una decisión autorizable.
