# ReplayUi v1

Proyección HTML read-only para evidencia de AgentReplayLifecycle. Reutiliza AgentReplayCore, AgentReplayLifecycle y UiTheme; no reinterpreta estados, causalidad ni categorías.

## Contrato

Entrada por fila: {category, stage, event}.

Categorías cerradas: code|ci|coordination|decisions|production|security. La categoría es explícita y no se infiere desde summary, source, actor ni kind. Una misma variante canónica de evento no puede aparecer ligada a categorías distintas.

stage y event se revalidan mediante el lifecycle existente. El filtro read-only acepta all o una categoría canónica y únicamente cambia la proyección visible.

## Evidencia y estados

Cada fila muestra categoría, etapa, kind, actor, summary y evidence_ref. GitHub HTTPS se renderiza como enlace; referencias internas quedan como code.

Los kinds skipped, startup_failure, failure y success se muestran literalmente. Los conflictos producidos por AgentReplayLifecycle permanecen como unknown; la UI no intenta resolverlos y, bajo un filtro activo, solo muestra conflictos ligados explícitamente a esa categoría.

## Seguridad y UX

Todo contenido dinámico se escapa. La validación de ReplayEvent sigue rechazando secretos, credenciales, email/PII no permitida, transcripts y reasoning interno.

La salida es mobile-first, usa focus-visible y respeta reduced motion. No contiene forms, POST, botones de mutación, DB, red, filesystem write ni shell.

Fuera de alcance: routing real, persistencia/adapters, reejecución de acciones, transcript opt-in e inferencia de categorías.
