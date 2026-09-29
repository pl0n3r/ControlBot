# Staff Control Plane v1 — lectura

El boundary read-only consume únicamente una proyección `/ops/staff` project-scoped que declara `population=staff_only`. Un proveedor que mezcle clientes finales, use un scope distinto o no pueda demostrar esa frontera falla cerrado.

`StaffControlPlane::summary()` normaliza conteos agregados y freshness sin PII. `StaffControlPlane::search()` procesa email solo durante la llamada, devuelve `masked_email`, ordena por `staff_id` y fija `directory_persisted=false`; no escribe DB, cache ni archivos.

Este slice no ejecuta invitaciones, suspensiones, cambios de rol ni recuperaciones. Esas mutaciones requieren el boundary privilegiado posterior con authority y passkey verificadas.

Privacidad: `datos.yml` declara identidad y contacto staff como tratamientos on-demand con retención `request_lifetime_only`, sin proveedor externo. D-063 se atesta para construcción porque este slice no está live y los fixtures no usan datos reales.
