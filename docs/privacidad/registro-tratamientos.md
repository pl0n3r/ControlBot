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
- Campos de software: `actor`, `action`, `target_type`, `target_id`, `result`, `created_at`
- Finalidad: `security_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## chat_response_opt_in

- Categoría: `usage`
- Campos de software: `agent_id`, `tab_id`, `response_text`, `received_at`, `opt_in`
- Finalidad: `agent_response_relay`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`
