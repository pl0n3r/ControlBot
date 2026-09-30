# Executive Cockpit UI v1

#442 completa la superficie visual de #127 sobre los contratos ya normalizados por `ExecutiveCockpit` (#317) y `OwnerInbox` (#276). El renderer es server-side, read-only y reutiliza `UiTheme`; no recalcula salud, freshness, authority, prioridad ni estado de trabajo.

## Entrada y semántica

`ExecutiveCockpitUi::render(cockpit, inbox)` recibe exclusivamente snapshots normalizados. Business health y technical health se muestran por separado. `stale|unknown` permanecen explícitos y nunca reciben presentación de healthy/current. Finance, Product, Infrastructure, responsable y Runtime se leen del agregado; no existen campos manuales paralelos.

Owner Inbox conserva `FYI | WATCH | DECISION | CRITICAL`. Las decisiones muestran refs de authority/decision/options/deadline solo como información. No hay approve/reject, WorkItem, mutación ni ejecución.

## Navegación y accesibilidad

La vista es mobile-first y usable en desktop. Cada Venture tiene un anchor interno determinista y el drill-down permanece en la misma página. No hay enlaces navegables a providers o URLs externas. El HTML usa viewport, landmarks, headings y labels; CSS incluye `prefers-reduced-motion` y no requiere JavaScript.

Todo texto se escapa. Shapes extra y material con forma de PII/secreto fallan cerrado en la frontera del renderer.

## Límites institucionales

Factory conserva WorkItem/readiness/ranking/dispatch. FactoryRunner ejecuta lo ya despachado. Decision Rights conserva autoridad. AEGIS conserva seguridad. La UI solo representa evidencia ya proyectada.

Reversión: retirar las cuatro rutas de #442; no hay DB, migración, red, cache, provider, scheduler ni estado externo.
