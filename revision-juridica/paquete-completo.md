# Paquete completo para revisión jurídica — ControlBot

Estado: documented_not_legally_approved

## Documento: docs/privacidad/aviso-privacidad.md

# Aviso de privacidad y autorización

> Estado: borrador técnico generado; **revisión jurídica requerida**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/ControlBot`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_profiles | identification | account_alias, profile_alias, browser, plan | account_inventory | review_required | review_required | ninguno_declarado | review_required |
| agent_status | usage | agent_id, account_alias, profile_alias, tab_id, status, last_heartbeat_at, mode, repository, issue_number | agent_orchestration | review_required | review_required | ninguno_declarado | review_required |
| audit_log | usage | actor, action, repository, issue, category, option, sha, result, evidence, at, snoozed_until | security_audit | review_required | review_required | ninguno_declarado | review_required |
| chat_response_opt_in | usage | agent_id, tab_id, repository, issue_number, question_text, response_text, status, received_at, opt_in | agent_response_relay | review_required | review_required | ninguno_declarado | review_required |
| dashboard_health_status | usage | health | dashboard_operational_status | review_required | review_required | ninguno_declarado | review_required |
| staff_directory_contact | contact | email, masked_email | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |
| staff_directory_identity | identification | staff_id, display_name, role, status, last_access_at, mfa_state | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.

---

## Documento: docs/privacidad/canal-derechos.md

# Canal para ejercer derechos sobre datos personales

> Estado: borrador técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/ControlBot`

## Responsable y canal

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Procedimiento pendiente

- Requisitos de la solicitud: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Flujo interno de atención: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Plazos aplicables: [COMPLETAR POR EL DUEÑO] — deben ser definidos con revisión jurídica; este kit no inventa un plazo legal.

El canal debe contemplar solicitudes de acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación del consentimiento cuando corresponda. La identidad, legitimación y representación deben verificarse antes de tramitar solicitudes; los procedimientos y plazos siguen sujetos a revisión jurídica. Este documento describe una frontera técnica y no constituye aprobación jurídica.

---

## Documento: docs/privacidad/politica-tratamiento.md

# Política de tratamiento de datos personales

> Estado: documento técnico generado; **no constituye aprobación jurídica**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/ControlBot`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_profiles | identification | account_alias, profile_alias, browser, plan | account_inventory | review_required | review_required | ninguno_declarado | review_required |
| agent_status | usage | agent_id, account_alias, profile_alias, tab_id, status, last_heartbeat_at, mode, repository, issue_number | agent_orchestration | review_required | review_required | ninguno_declarado | review_required |
| audit_log | usage | actor, action, repository, issue, category, option, sha, result, evidence, at, snoozed_until | security_audit | review_required | review_required | ninguno_declarado | review_required |
| chat_response_opt_in | usage | agent_id, tab_id, repository, issue_number, question_text, response_text, status, received_at, opt_in | agent_response_relay | review_required | review_required | ninguno_declarado | review_required |
| dashboard_health_status | usage | health | dashboard_operational_status | review_required | review_required | ninguno_declarado | review_required |
| staff_directory_contact | contact | email, masked_email | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |
| staff_directory_identity | identification | staff_id, display_name, role, status, last_access_at, mfa_state | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |

## Derechos y revisión

Las solicitudes de acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación del consentimiento, cuando corresponda, se canalizan mediante el canal de derechos indicado arriba, previa verificación de identidad y legitimación. La portabilidad no implica que exista un servicio automatizado de exportación. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.

---

## Documento: docs/privacidad/registro-tratamientos.md

# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/ControlBot`

## account_profiles

- Categoría: `identification`
- Campos de software: `account_alias`, `profile_alias`, `browser`, `plan`
- Finalidad: `account_inventory`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## agent_status

- Categoría: `usage`
- Campos de software: `agent_id`, `account_alias`, `profile_alias`, `tab_id`, `status`, `last_heartbeat_at`, `mode`, `repository`, `issue_number`
- Finalidad: `agent_orchestration`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## audit_log

- Categoría: `usage`
- Campos de software: `actor`, `action`, `repository`, `issue`, `category`, `option`, `sha`, `result`, `evidence`, `at`, `snoozed_until`
- Finalidad: `security_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## chat_response_opt_in

- Categoría: `usage`
- Campos de software: `agent_id`, `tab_id`, `repository`, `issue_number`, `question_text`, `response_text`, `status`, `received_at`, `opt_in`
- Finalidad: `agent_response_relay`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## dashboard_health_status

- Categoría: `usage`
- Campos de software: `health`
- Finalidad: `dashboard_operational_status`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## staff_directory_contact

- Categoría: `contact`
- Campos de software: `email`, `masked_email`
- Finalidad: `staff_administration`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `request_lifetime_only`

## staff_directory_identity

- Categoría: `identification`
- Campos de software: `staff_id`, `display_name`, `role`, `status`, `last_access_at`, `mfa_state`
- Finalidad: `staff_administration`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `request_lifetime_only`

---

## Documento: docs/privacidad/retencion.md

# Tabla de retención

> Estado: calendario técnico documentado; **revisión jurídica requerida**.

Producto: `pl0n3r/ControlBot`

| Tratamiento | Categoría | Retención declarada | Máximo común |
| --- | --- | --- | --- |
| account_profiles | identification | review_required | review_required |
| agent_status | usage | review_required | review_required |
| audit_log | usage | review_required | review_required |
| chat_response_opt_in | usage | review_required | review_required |
| dashboard_health_status | usage | review_required | review_required |
| staff_directory_contact | contact | request_lifetime_only | review_required |
| staff_directory_identity | identification | request_lifetime_only | review_required |

---

## Documento: docs/privacidad/terminos-condiciones.md

# Términos y condiciones

> Estado: borrador técnico generado; **revisión jurídica requerida antes de publicación**.

Producto: `pl0n3r/ControlBot`

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Condiciones pendientes de definición

- Condiciones comerciales: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Niveles de servicio (SLA), si aplican: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Garantías y limitaciones aplicables: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Jurisdicción y mecanismo de solución de controversias: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.

Este documento no inventa ni presume condiciones del producto. Los hechos comerciales y jurídicos anteriores deben completarse y aprobarse antes de su uso público.

---

