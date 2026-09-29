# Incident Timeline + Postmortem v1

Este slice separa evidencia temporal de clasificación causal. IncidentTimeline normaliza eventos append-only para lectura estable; Postmortem valida categorías declaradas contra una relación de evidencia explícita y no infiere causalidad por proximidad temporal.

## Timeline

- Orden de render: timestamp + sequence + event_id.
- source_index conserva el orden original y source conserva la procedencia declarada del evento.
- duration_seconds mide detected_at → recovered_at; opened_at se conserva como contexto temporal.
- Un incidente sin recovery conserva duración null; no se inventa MTTR.
- detected_at no puede anteceder opened_at y recovered_at no puede anteceder detected_at.

## Postmortem

Categorías cerradas: root_cause | independent_bug | contributing_factor | preventive_change | unknown.

Cada finding declara evidence_relation: necessary_cause | independent_defect | contributor | preventive_only | unresolved. Con evidencia supported, la clasificación debe coincidir con esa relación; una medida preventive_only no puede autocertificarse como root_cause. Evidencia incomplete o contradictory exige unresolved + unknown + owner_action_required=true.

Los findings conservan evidence_ref y un resumen sanitizado. Fechas ISO ordinarias no se confunden con teléfonos; secretos, email y patrones telefónicos plausibles se rechazan. El módulo no consulta red, DB, billing ni historial externo.

El fixture #78 conserva:
- capacidad privada agotada como causa raíz demostrada para ese periodo;
- checks: write como bug independiente;
- fan-out/coordinación como factor contribuyente;
- cambio del observador 1h→6h como prevención, no causa;
- mecanismo exacto de billing como unknown cuando no está demostrado;
- #86 como canario único y recuperación serial con cola #72 → #77 → #80 → #71 → #84 → #73 → #75, nunca fan-out.

## Límites

Sin publicación de lecciones, UI, alertas, scheduler, mutaciones ni cierre automático de incidentes. Este contrato solo normaliza y valida evidencia ya aportada.
