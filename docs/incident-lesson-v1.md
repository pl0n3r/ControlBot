# Incident Lesson v1

`IncidentLesson` convierte un postmortem ya validado por `Postmortem` en un `LessonCandidate` determinista. No vuelve a inferir causalidad y no publica nada fuera de ControlBot.

## Límite

Entrada: el contrato normalizado de `Postmortem v1`. El adaptador conserva las categorías existentes y solo proyecta hechos soportados:

- `root_cause` → `root_cause_facts`;
- `independent_bug` → `independent_bugs`;
- `contributing_factor` → `contributing_factors`;
- `preventive_change` → `preventive_rules`.

Evidencia `incomplete|contradictory` permanece fuera de las colecciones de hechos soportados y obliga `owner_action_required=true`. Un bug independiente nunca se promociona a causa o regla preventiva.

## Identidad e idempotencia

Colecciones y referencias se deduplican y ordenan antes de calcular SHA-256. Inputs causalmente equivalentes producen el mismo `candidate_fingerprint` y el mismo `dedupe_marker=incident-lesson-v1:<sha256>`.

La salida fija `publication_state=pending`. Publicar en `factory/lecciones`, persistir, cerrar incidentes o ejecutar acciones cross-repo pertenece a otro adapter y no forma parte de este núcleo.

## Seguridad y privacidad

Summary/findings se aceptan solo si ya son texto acotado y no contienen señales de secretos, tokens, cookies, credenciales, email o PII numérica plausible. `incident_ref`, `finding_ref` y `evidence_ref` conservan referencias opacas, no payloads.

## Fixture #78

La lección conserva agotamiento de capacidad privada como causa soportada, `checks: write` como bug independiente, fan-out como factor contribuyente y el cambio de cadencia del observador como prevención. El mecanismo de billing no demostrado permanece unresolved y requiere acción del dueño.
