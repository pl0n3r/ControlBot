# External API Session Source v1

## Problema

`ExternalApiSession` valida estructura, expiración y revocación, pero esos checks no prueban por sí solos quién produjo los records. Antes de conectar un request adapter productivo, ControlBot necesita una frontera que obtenga el estado vigente desde una fuente **server-side autoritativa**.

## ExternalApiSessionSource

La interfaz recibe únicamente:

- `identity_id` y `scope` ya derivados de `VerifiedAccessContext`;
- referencias opacas de device/session/step-up;
- `now` del servidor.

La implementación concreta recupera el estado canónico. No recibe ni acepta un payload de estado autocertificado por el cliente.

La respuesta canónica incluye device, session, step-up opcional, `observed_at`, `freshness` y un `source_ref` interno. `ExternalApiSessionSourceContract` vuelve a validar todos los bindings mediante `ExternalApiSession`.

## Fail closed

El contexto no se construye cuando existe cualquiera de estas condiciones:

- freshness `stale` o `unknown`;
- observación futura;
- identity/scope/device/session/step-up mismatch;
- device, session o step-up revocado;
- session o step-up expirado;
- step-up inesperado o faltante para la referencia solicitada.

No existe fallback a arrays raw provenientes del cliente.

## VerifiedExternalSessionContext

Solo `fromSource()` puede construir el contexto. El constructor es privado y el objeto no puede serializarse.

El contexto conserva únicamente metadatos nominales normalizados y una proyección de auditoría secret-free:

- identity y scope;
- refs opacas device/session/step-up;
- source y observed_at;
- freshness.

No contiene ni concede capability, authority, policy o decisión de autorización. Esa responsabilidad continúa en `ExternalApiAccess` y el Request Gate.

## Material sensible

El contrato no expone tokens, cookies, OTP, credenciales, secretos ni claves. Las respuestas usan campos cerrados, por lo que material extra inyectado es rechazado.

## Fuera de alcance

Este slice no elige persistencia, DB/Redis, OAuth/JWT, provider SDK, Keychain/Secure Enclave, request adapter HTTP, código iOS, push notifications ni Factory WorkItems.
