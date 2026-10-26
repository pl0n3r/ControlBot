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
| owner_login_credentials | authentication | password_hash | owner_login_security | review_required | review_required | ninguno_declarado | review_required |
| staff_directory_contact | contact | email, masked_email | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |
| staff_directory_identity | identification | staff_id, display_name, role, status, last_access_at, mfa_state | staff_administration | review_required | review_required | ninguno_declarado | request_lifetime_only |

## Derechos y revisión

Las solicitudes de acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación del consentimiento, cuando corresponda, se canalizan mediante el canal de derechos indicado arriba, previa verificación de identidad y legitimación. La portabilidad no implica que exista un servicio automatizado de exportación. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.
