# Venture access → Owner Decisions

`DecisionRuntime::executeVentureAccess` es server-side: valida repo contra allowlist y exige el token GitHub de la sesión antes de lifecycle. El cliente no aporta writer, repo ni token.

Si `VentureAccessRuntime` devuelve `owner_decision_gate`, se revalida como `product-direction` con recommendation/default B. La Issue conserva `factory-human-gate`, recibe label `factory-human-gate` y añade `venture-access-materialization` con `command_id` + `idempotency_key`. La recuperación consulta solo Issues con ese label y acepta únicamente autores `OWNER/MEMBER/COLLABORATOR`.

La idempotencia cross-session usa el `AppendOnlyAuditLog` durable existente. Antes de POST se escribe un claim `pending` bajo una clave SHA-256 de repo + command + idempotency key en un repositorio sentinela fuera de la allowlist de historial. El primer claim append-only puede crear; otros fallan cerrado o recuperan. Tras respuesta válida se añade `finalized` con número/URL de Issue. Si GitHub acepta POST pero la respuesta se pierde, un replay con sesión nueva no vuelve a POST: recupera exactamente una Issue confiable y finaliza el ledger; si aún no es visible, queda bloqueado.

`GateInbox` descubre la Issue sin cambios. Aprobar sigue usando `ApprovalEndpoint/OwnerApprovalService`: comenta y cierra la Issue, pero nunca reejecuta el comando Venture original.

Fuera de alcance: cambios a `GateInbox.php`, `ApprovalEndpoint.php`, SSO, UI, secretos o reejecución automática.
