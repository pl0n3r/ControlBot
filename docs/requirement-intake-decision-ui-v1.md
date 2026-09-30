# Requirement Intake Decision UI v1

`RequirementIntakeDecisionUi` convierte un `EpicProposal v1` canónico en una proyección de revisión humana determinista. El módulo no aprueba, rechaza, materializa ni persiste nada: solo describe qué se vería y qué se propondría si una decisión posterior autorizara trabajo.

## Contrato de salida

`RequirementDecisionView v1` conserva `proposal_ref`, `proposal_fingerprint` y `draft_ref` como provenance mínima. La vista contiene:

- `summary`: problema, usuario, objetivos, fuera de alcance y lista explícita de campos `unknown`;
- `impacts`: riesgos, dependencias, dudas y `project_match` del proposal original;
- `materialization_diff`: previsualización tipada de Project, Epic e Issues, todos con `execution=false`;
- `decision_options`: `approve | revise | reject` como intenciones tipadas, nunca como acciones ejecutadas;
- `surfaces`: contrato equivalente desktop/mobile con controles accesibles por teclado y sin dependencia exclusiva de gestos;
- `view_ref` y `fingerprint` deterministas para el mismo proposal.

## Diff de materialización

La previsualización no inventa contenido. Un Project ya matched se representa como `link_existing`; un match ambiguo como `review_matches`; la ausencia de match como `propose_create`. Epic e Issues se derivan únicamente de los campos y slices ya presentes en el `EpicProposal`.

Ningún elemento del diff ejecuta una mutación. La materialización idempotente pertenece a un slice posterior y requiere una decisión aprobada explícita.

## Privacidad y minimización

El módulo valida que el proposal sea canónico e íntegro antes de proyectarlo y vuelve a aplicar detección de material sensible al output. No acepta ni emite audio bruto, transcript bruto, credenciales, secretos o payloads de proveedor. Los campos desconocidos permanecen `unknown`; no se convierten en hechos ni se rellenan con defaults de negocio.

## Pureza

El módulo no contiene acceso a DB, filesystem, red, GitHub, workflows, providers, cron ni comandos de sistema. Es una función pura: proposal canónico de entrada, vista determinista de salida.
