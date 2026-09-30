# WeeklyFocus v1

`WeeklyFocus` modela el foco semanal del dueño como una preferencia versionada. No es una prioridad nueva ni una autoridad paralela del Factory Dispatcher.

## Boundary de autoridad

- Factory Dispatcher V2 / Scheduler canónico decide elegibilidad, precedencia y `selected_key`.
- `WeeklyFocus::preference()` solo opera sobre un **cohort ya certificado como equivalente** por esa autoridad canónica.
- El módulo no acepta `policy_rank`, no calcula un ranking global y no devuelve `selected_key`.
- Si recibe un cohort ready con prioridades canónicas distintas, falla cerrado con `WeeklyFocus canonical cohort mismatch`.
- Candidatos bloqueados o refs `unavailable` nunca se vuelven elegibles por aparecer primero en el foco.

## Preferencia reproducible

- `ordered_refs` acepta únicamente refs tipadas `controlbot:project/...` o `controlbot:epic/...`; URLs de Issues u otras refs no tipadas fallan cerrado. Puede estar vacío para representar “sin foco explícito”.
- Dentro de un cohort homogéneo y elegible, `preference()` devuelve `preferred_key` y provenance: `focus_version`, `focus_position`, `request_fingerprint`, `focus_influenced` y `preference_reason`. `focus_position` es 1-based para ser compatible con `SchedulerPolicyGuard`; `focus_influenced=true` solo cuando el orden de foco cambia realmente el ganador respecto al desempate lexical base.
- `preferred_key` es una sugerencia/tie-break para el flujo canónico; no certifica una selección global.
- Cambiar el foco solo afecta decisiones futuras. No preempta ni reordena trabajo running.

## Versionado e historial

- `revise()` exige `expected_version` y falla cerrado ante conflictos; cada cambio incrementa la versión.
- Una ref `unavailable` permanece auditable en el foco.
- `activeAt()` reconstruye qué versión estaba activa en un instante dado.

El módulo es puro: no persiste, no ejecuta Scheduler writes, no hace I/O y no contiene lógica de despacho global.
