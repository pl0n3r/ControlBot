# Customer Feedback Signal v1

## Propósito

`CustomerFeedbackSignal` convierte únicamente evidencia ya normalizada por
`CustomerSuccessCore` en una señal agregada y consumer-neutral. Su frontera es
Customer Success → Product/Discovery; no ejecuta ninguno de esos dominios.

## Entradas confiables

La señal se construye desde uno de dos contratos existentes:

- `SupportSignal` validada por `CustomerSuccessCore::supportSignal()`.
- una dimensión de `CustomerSuccessSnapshot` validada por
  `CustomerSuccessCore::snapshot()`.

El caller solo aporta `version`, `feedback_ref` y `targets`. No puede
inyectar scope, product, freshness, evidence, confidence o nature por fuera de
la fuente normalizada.

## Semántica

Orígenes cerrados:

- `support_pattern`
- `health_exception`
- `satisfaction_signal`
- `onboarding_friction`
- `renewal_signal`

Targets cerrados:

- `product_intelligence`
- `discovery`

`product_intelligence` requiere que la fuente normalizada tenga
`product_ref` explícito. Los targets se ordenan y no admiten duplicados.

La señal conserva `venture_id`, `product_ref`, `source_ref`,
`evidence_refs`, `observed_at`, `freshness`, `confidence` y `nature`.
Una fuente `unknown` produce `nature=unknown`, sin evidence ni
`observed_at`. Una fuente `stale` continúa stale; una dimensión inferred
continúa inferred. El contrato nunca convierte ausencia de evidencia en dato
observado.

## Privacidad

Los identificadores de feedback y evidencia son refs opacas. El schema cerrado
rechaza transcript, ticket/message body, nombres, email, teléfono, IP,
attachments y texto libre de cliente. La salida no transporta payloads de
tickets ni conversaciones.

## Límites

La señal declara consumidores posibles, pero no llama Product Intelligence ni
Discovery. Tampoco crea hipótesis, decisiones, prioridad, scores, trabajo,
persistencia, providers, scheduler, notificaciones o colas.

El handoff técnico a Factory sigue siendo responsabilidad separada de
`CustomerSuccessWorkOrigin` (#266). La futura integración con Discovery puede
consumir esta señal cuando el contrato #240 esté disponible sin cambiar esta
frontera.
