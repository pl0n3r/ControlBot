# External API Trusted Request Gate v1

## Frontera
`ExternalApiRequestGate` ya no recibe device/session/step-up raw. Su única entrada de autenticación es `VerifiedExternalSessionContext`, construido desde `ExternalApiSessionSource` server-side.

El gate compara identity/scope con `VerifiedAccessContext`, revalida expiración al `now` actual y consulta `ExternalApiAccess` como única fuente de capability, authority, policy y decisión.

## Decisiones
- `deny` permanece deny aunque exista step-up.
- `allow` de lectura no exige step-up.
- `step_up_required` permanece así sin evidencia verificada y solo pasa a allow con step-up vigente del mismo contexto.

El output conserva refs opacas device/session/step-up para auditoría, sin payloads del autenticador ni secretos.

## Fuera de alcance
HTTP adapter, tokens, persistencia de sesión, providers, iOS/push, Owner Decisions, Factory WorkItems y ejecución.
