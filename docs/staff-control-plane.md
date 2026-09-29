# Staff Control Plane v1

El boundary de lectura consume únicamente una proyección `/ops/staff` project-scoped con `population=staff_only`. Una fuente que mezcle clientes finales, use otro scope o no pueda demostrar esa frontera falla cerrado. Search procesa email solo durante la llamada, devuelve `masked_email` y fija `directory_persisted=false`.

## Acciones privilegiadas

`StaffControlPlane::actionIntent()` no ejecuta el producto. Construye un intent tipado únicamente desde un `VerifiedAccessContext` y un `VerifiedExternalSessionContext` nominales. Exige identidad humana activa, role Owner, capability `staff.manage`, authority `L4_OWNER`, scope exacto `project:<project_id>` y step-up vigente con método `passkey`.

Las acciones permitidas son `staff.invite`, `staff.suspend`, `staff.reactivate`, `staff.role.change` y `staff.password_recovery.send`. Invite y role change requieren un rol presente en el allowlist suministrado por el producto. El target es una referencia opaca; ControlBot no recibe email de invitación, password, activation/reset token ni recovery code en este boundary.

Cada intent deriva una `idempotency_key` estable de su identidad, proyecto, target, acción y rol. Reejecutar el mismo intent conserva la misma clave.

## Resultado y reconciliación

`recordOutcome()` acepta únicamente `applied|denied|unknown`, exige la misma idempotency key y emite auditoría minimizada con refs separadas de ControlBot y del producto. Un resultado `unknown` fija `retry_allowed=false` y `reconciliation_required=true`.

`reconcile()` solo acepta un outcome previo unknown ligado al mismo intent y un status lookup `applied|denied|not_found`. Solo `not_found` habilita retry. La reconciliación no vuelve a ejecutar la mutación.

No hay DB directa, adapter HTTP real, credenciales de servicio, acciones masivas cross-project, UI ni ejecución autónoma por agentes.
