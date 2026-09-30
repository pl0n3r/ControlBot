# Infrastructure Control Center — E2E boundary

Issue #191 demuestra la composición de los contratos existentes; no añade un
control plane, adapter de proveedor ni ruta de ejecución nueva.

## Flujo canónico

`Provider/Resource → Observation/Freshness → Impact → CenterUi → InfrastructureIntent
→ Factory WorkItem → RunnerRequest → RunnerGateway → ExecutionEvent/Evidence`.

`InfrastructureActionProjection` es una vista read-only de una decisión gobernada.
Siempre conserva `execution=false` y no sustituye al WorkItem ni al RunnerRequest.

## Responsabilidades

- **ControlBot** observa recursos, conserva freshness/impacto, expresa la intención,
  evalúa authority/policy/budget y materializa WorkItem/RunnerRequest gobernados.
- **Factory** define contratos, gates y la cola única de trabajo. ControlBot no crea
  un scheduler ni una cola paralela.
- **FactoryRunner** recibe órdenes tipadas, ejecuta el adapter autorizado y devuelve
  eventos/evidencia; ControlBot no llama directamente a APIs de proveedor.
- **AEGIS** aporta identidad, least privilege, seguridad, riesgo y auditoría; no
  ejecuta operaciones de infraestructura.
- **CAPITAL** decide presupuesto/costo cuando aplica; una decisión financiera no
  concede authority operacional.

## Fail-closed

Freshness `stale|unknown`, authority insuficiente, evidencia financiera ausente,
payloads con secretos o eventos ajenos a la orden no producen una ruta válida de
ejecución. Owner Decision conserva `approval_ref` y no produce RunnerRequest.

El E2E usa fixtures deterministas y `RunnerGateway`; no usa AWS, Hostinger, SSH,
HTTP de proveedor, credenciales reales, dinero real ni producción.
