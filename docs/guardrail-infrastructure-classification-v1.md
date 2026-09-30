# Guardrail Infrastructure Classification v1

Este adapter separa evidencia de agente (#109) de evidencia externa de infraestructura (#78). Un fallo `startup_failure` sin steps nunca basta para atribuir `agent_stuck`.

`blocked_by_infrastructure` exige fallos frescos con `outcome=failure` en al menos dos repos privados distintos, `runner_id=0`, mismo fingerprint/dependencia, contraste público con runner/steps reales y `outcome=success`, y una señal externa fresca coincidente. Evidencia insuficiente o contradictoria queda `unknown`.

Mientras persiste el fingerprint externo se proyecta `suppress_retry` idempotente y un handoff `waiting_dependency`. Recovery solo proyecta un WorkItem canario si la señal healthy es estrictamente posterior al último fallo correlacionado, cambia el fingerprint y conserva la misma dependencia; el `source_ref` del grupo se canoniza para que handoff/canary no dependan del orden de entrada. `queue_release_allowed` permanece false.

El módulo no consulta providers, billing, red ni DB y no ejecuta pausa, retry, scheduler writes o canarios.
