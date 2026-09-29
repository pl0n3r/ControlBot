# Customer Success → Factory Work Origin

Este slice implementa el handoff de #185 hacia la cola única definida por Factory #269. No ejecuta Factory, no decide readiness y no crea scheduler o ranking local.

## Orígenes

`CustomerSuccessWorkOrigin` acepta únicamente estructuras que primero pasan por `CustomerSuccessCore`:

- `fromSupportSignal()`: usa una `SupportSignal` agregada;
- `fromHealthException()`: selecciona una dimensión explícita de un `CustomerSuccessSnapshot`.

Ambos producen `origin_mode=automatic`, `origin_system=controlbot` y `producer_ref=controlbot:customer-success`, preservando el Venture.

La severidad de Support se copia únicamente al campo Factory `severity`. No determina `priority_class`, `authority_level`, `work_type`, capabilities ni roles.

## Authority y policy

Los campos que conceden o condicionan trabajo llegan explícitamente desde policy/caller: work type, capabilities, roles, authority, priority, claims, dependencies y policy ref. La señal de Customer Success nunca los infiere.

`observed_at` también es input explícito del origen y se normaliza a UTC. Factory WorkItem v1 no admite un campo `freshness` top-level; la freshness original permanece en la señal/snapshot referenciada por `evidence_refs`. El bridge no convierte stale, unknown o churn inferido en `ready`, `approved` o `authorized`.

## Evidencia e idempotencia

Evidence refs contienen la referencia estable de la señal/snapshot y sus refs opacas de producto/evidencia disponibles. No se copian transcript, ticket body, mensajes ni identidad humana.

La idempotency key depende solo del origen normalizado + `work_type`; reordenar capabilities, roles, dependencies o claims no crea trabajo duplicado.

## Límite

El resultado sigue Work Origin Contract v1 de Factory #269. No contiene provider/model/executor/dispatch, no persiste, no llama FactoryRunner, no notifica y no materializa una cola paralela.
