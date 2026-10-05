# GitHub Control Plane wiring

`ControlBotWebEntrypoint` intercepta únicamente `/github` y `/projects/{project_id}/github`. Las demás rutas conservan `FactoryOrchestratorWebEntrypoint`.

Las respuestas HTML GitHub ya validadas se convierten a contenido interno y CSS del mismo documento antes de pasarlas a `ControlCenterShell::render(..., 'github', ...)`; así hay un único `html/body`, navegación canónica, mobile-first y foco visible.

El bootstrap público solo llama al router. Este wiring no añade API GitHub, credenciales, acciones mutantes, deploy ni go-live. Los estados 403/404/405/503 de los entrypoints GitHub se preservan sin envolver ni filtrar evidencia.
