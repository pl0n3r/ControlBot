# External API Request Gate v1

## Propósito
Compone autenticación nominal (#268) y autorización server-side (#245). No crea RBAC, sesiones, tokens ni ejecución.

## Regla
1. Valida device/session con `ExternalApiSession` usando identity y scope del `VerifiedAccessContext`.
2. Obtiene la decisión base exclusivamente de `ExternalApiAccess`.
3. `deny` permanece `deny`; step-up nunca lo eleva.
4. `allow` de lectura requiere sesión activa y no necesita step-up.
5. `step_up_required` solo pasa a `allow` con evidencia vigente y ligada a la misma identity/session/device/scope.

La salida conserva operation/capability/scope/mutation y refs opacas de session/device/step-up para auditoría. No acepta capability, authority, policy o decision como datos del cliente.

## Fail-closed
Revocación, expiración o mismatch de device/session/step-up falla cerrado. Step-up demuestra recencia, no autoridad.

## Fuera de alcance
Autenticar passkeys/MFA, emitir tokens, persistir auditoría, cambiar OpenAPI, crear WorkItems o ejecutar mutaciones.
