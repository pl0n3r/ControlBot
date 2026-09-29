# Incident UI v1

Slice UI de #11. Compone únicamente salidas ya normalizadas de `IncidentTimeline`, `Postmortem` e `IncidentLesson`; no recalcula MTTR, causalidad ni lesson fingerprints.

La vista es server-side, determinista, read-only y mobile-first. Timeline, categorías causales, recovery y lesson candidate conservan sus evidence refs. El lesson candidate muestra además `publication_state`, `candidate_fingerprint` y `dedupe_marker`. Los campos aún no materializados por los cores actuales —severidad, impacto y fix permanente— se muestran explícitamente como `UNKNOWN` en vez de inventarse.

Todo contenido dinámico se escapa. Payloads con passwords, tokens, cookies, authorization headers, private/API keys o bearer credentials fallan cerrado antes del render. No hay DB, red, shell, publicación cross-repo ni acciones.
