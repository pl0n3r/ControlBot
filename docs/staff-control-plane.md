# Staff Control Plane v1 — lectura

El boundary read-only consume únicamente una proyección `/ops/staff` project-scoped que declara `population=staff_only`. Un proveedor que mezcle clientes finales, use un scope distinto o no pueda demostrar esa frontera falla cerrado.

`StaffControlPlane::summary()` normaliza conteos agregados y freshness sin PII. `StaffControlPlane::search()` procesa email solo durante la llamada, devuelve `masked_email`, ordena por `staff_id` y fija `directory_persisted=false`; no escribe DB, cache ni archivos.

Este slice no ejecuta invitaciones, suspensiones, cambios de rol ni recuperaciones. Esas mutaciones requieren el boundary privilegiado posterior con authority y passkey verificadas.

Privacidad: `datos.yml` declara identidad y contacto staff como tratamientos on-demand con retención `request_lifetime_only`, sin proveedor externo. D-063 se atesta para construcción porque este slice no está live y los fixtures no usan datos reales.

## Acciones privilegiadas

Las mutaciones usan un `StaffActionIntent` project-scoped y un `StaffProductGateway` server-side. ControlBot no conoce credenciales de servicio, passwords, activation tokens ni recovery codes del producto.

Cada acción exige identidad humana con autoridad `L4_OWNER`, capability `staff.manage`, scope `project:<id>` y un `VerifiedExternalSessionContext` ligado a la misma identidad con step-up `passkey` todavía vigente. Los roles de invite/change-role se validan contra el allowlist devuelto por el producto.

La idempotency key se deriva de `project_id + intent_id + action + staff_ref + requested_role`. Antes de mutar se consulta el status; un outcome `unknown` se reconcilia por lookup y nunca provoca una segunda mutación automática. Cada intento escribe auditoría minimizada de ControlBot y exige `product_audit_ref` para resultados no `not_found`.
