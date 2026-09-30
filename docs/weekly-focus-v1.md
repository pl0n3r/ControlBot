# WeeklyFocus v1

`WeeklyFocus` modela el foco semanal del dueño como preferencia versionada, no como prioridad nueva.

- `ordered_refs` conserva Project/Epic canónicos en el orden elegido; puede estar vacío para representar “sin foco explícito”.
- `revise()` exige `expected_version` y falla cerrado ante conflictos; cada cambio incrementa la versión.
- `select()` consume candidatos canónicos de `SchedulerSelection`. El caller entrega `policy_rank` ya resuelto por las policies de Scheduler y el estado de la ref.
- Primero se respeta `policy_rank`, luego prioridad y elegibilidad; el foco solo desempata dentro del cohort equivalente.
- Una ref `unavailable` permanece en el foco pero no puede ser seleccionada.
- La salida registra `focus_version`, posición y si el foco influyó.
- `activeAt()` reconstruye la versión activa para una decisión histórica.

El módulo es puro: no persiste, no reordena WorkItems existentes, no preempta trabajo running y no hace I/O.
