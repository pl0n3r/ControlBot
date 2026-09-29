# Product Health Snapshot v1

Product Health Snapshot es un contrato de lectura para Cockpit #127. Resume métricas agregadas por Venture/Product/surface/period sin producir un veredicto global.

Cada dimensión conserva status, value, unit, sample, freshness, confidence, nature y provenance. Las categorías son únicas y se ordenan de forma determinista.

Reason codes cerrados: `unknown`, `insufficient_data`, `stale`, `freshness_unknown`, `inferred`, `observed_fresh`. `observed_fresh` solo describe evidencia measured + fresh + observed; no significa “bueno”.

Freshness global degrada en orden fail-closed: cualquier `unknown` domina, luego `stale`, y solo todas fresh producen `fresh`. Los reasons top-level son la unión ordenada de las dimensiones.

Este contrato no mezcla Customer Success, salud técnica o finanzas. Tampoco recomienda, decide, crea WorkItems, persiste datos ni define UI.
