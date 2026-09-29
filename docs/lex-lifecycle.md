# LEX lifecycle: construcción vs live

Esta política aplica la decisión del owner posterior al E2E de LEX. No interpreta leyes ni sustituye LexCore: proyecta qué puede continuar durante construcción y qué transición exige gate jurídico humano.

## Construcción

Mientras el producto esté en `construction`, una revisión jurídica pendiente se representa como `construction_legal_status=documented_not_legally_approved`. Ese estado **no es COMPLIANT** y no autoriza live.

Trabajo técnico reversible puede continuar cuando no existe riesgo jurídico material explícito ni una revisión humana rechazada. Si aparece riesgo material, el scope afectado falla cerrado también durante construcción.

## Live y datos reales

`live_legal_gate` solo pasa cuando:

- el estado LEX es `compliant` o `not_applicable`;
- existe revisión jurídica humana `approved`;
- la aprobación tiene evidencia referenciada;
- no existe riesgo jurídico material.

La transición a `live` o una solicitud de datos reales requiere ese gate. GAP, UNKNOWN, revisión pendiente/rechazada o evidencia insuficiente bloquean únicamente la transición/scope afectado. **UNKNOWN nunca es aprobación legal**.

## Autoridad

La proyección conserva `authority_effect=none` y `auto_execute=false`. No ejecuta providers, WorkItems, despliegues ni cambios de datos. Factory conserva Queue/authority; FactoryRunner conserva execution plane.

La revisión humana acredita únicamente el scope/evidencia referenciados. No convierte a LEX en asesor jurídico ni elimina futuras revisiones por cambios de mercado, datos, proveedores o regulación.
