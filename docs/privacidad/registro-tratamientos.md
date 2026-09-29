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
- Campos de software: `actor`, `action`, `repository`, `issue`, `category`, `option`, `sha`, `result`, `evidence`, `at`
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
