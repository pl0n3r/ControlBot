# Business OS · integración Venture access

`VentureAccessRuntime` es la fachada server-side del slice #134. Recibe una
solicitud lifecycle **sin identidad ni scope de cliente** y reconstruye ambos
desde `TrustedAccessContext`. Compone `IdentityCenter`, `DecisionRights` y
`VentureIdentity`; no crea un segundo modelo de grants o autoridad.

El contexto confiable incluye el grant verificado y dos resultados externos:
`budget_guard` y `production_authority`. La fachada no calcula ninguno.
Solo acepta `allow|deny|owner_decision_required` y combina los resultados de
forma monotónica: una fuente externa puede restringir o escalar, nunca convertir
un deny/escalamiento de Decision Rights en allow. Una restricción externa se
registra en `state.audit` usando el mismo `command_id` e `idempotency_key`; un
reintento reproduce ese deny/escalamiento y no aplica después la mutación.

Cuando el resultado requiere Owner, la mutación actual no se ejecuta y se
devuelve `owner_decision_gate`: un marker `factory-human-gate` válido,
`product-direction`, con default seguro B. El orquestador existente debe
materializar ese marker como Issue; `GateInbox` y `ApprovalEndpoint` siguen
siendo el único flujo de decisión. Aprobar no reejecuta esta llamada: se requiere
un comando posterior, nuevamente validado, con autoridad server-side adecuada.

La auditoría de la fachada contiene solo IDs controlados, actor, scope,
operación, clase de evento, outcome, reason code y timestamp. Un replay de un
comando ya aplicado conserva el outcome `applied` y el timestamp del evento
original, aunque el status de la fachada sea `already_applied`. No incluye payload,
passwords, hashes, tokens, cookies, OTP, recovery codes ni secretos 2FA.

Fuera de alcance: DB, SSO, credenciales, pagos, UI, AutoFactory y go-live.
