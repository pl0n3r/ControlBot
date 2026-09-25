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
| audit_log | usage | actor, action, target_type, target_id, result, created_at | security_audit | review_required | review_required | ninguno_declarado | review_required |
| chat_response_opt_in | usage | agent_id, tab_id, response_text, received_at, opt_in | agent_response_relay | review_required | review_required | ninguno_declarado | review_required |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.
