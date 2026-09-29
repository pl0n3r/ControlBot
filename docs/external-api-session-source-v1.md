# External API Session Source v1

## Frontera de confianza
`ExternalApiSession` valida forma, expiración y revocación; no demuestra quién produjo los records. Antes de un adapter HTTP productivo, `ExternalApiSessionSource` obtiene el estado vigente desde una fuente server-side autoritativa.

La consulta recibe solo identity/scope derivados de `VerifiedAccessContext`, refs opacas device/session/step-up y `now`. No acepta arrays de estado autocertificados por el cliente. La respuesta aporta device, session, step-up opcional, `observed_at`, `freshness` y `source_ref`; el contrato vuelve a validar bindings con `ExternalApiSession`.

## Fail closed
Se rechazan freshness `stale|unknown`, observación futura, mismatches, revocación o expiración, y step-up faltante/inesperado. No existe fallback a payloads raw del cliente.

## VerifiedExternalSessionContext
Solo `fromSource()` lo construye; el constructor es privado y el objeto no se serializa. Expone metadata normalizada y un resumen secret-free con identity/scope, refs opacas, source, observed_at y freshness. No concede capability, authority, policy ni decisión.

## Sensibles y alcance
No expone tokens, cookies, OTP, credenciales, secretos o claves; campos extra son rechazados. Fuera de alcance: persistencia DB/Redis, OAuth/JWT, provider SDK, Keychain/Secure Enclave, adapter HTTP, iOS, push y Factory WorkItems.
