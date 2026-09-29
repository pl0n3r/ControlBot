# Vendor Owner Inbox Projection v1

## Propósito

Este bridge conecta excepciones materiales de Vendor Governance con Owner Inbox sin introducir una política paralela. La señal siempre se recalcula mediante `VendorExceptionSignal::project()`; el caller solo puede seleccionar una señal que realmente exista.

## Autoridad explícita

El bridge no deriva `fyi|watch|decision|critical`, authority, decision, options o deadline desde criticality, health, renewal o review state. Esos campos llegan explícitamente desde policy/caller y el resultado se valida otra vez con `OwnerInbox::entry()`.

Por tanto, la misma excepción de proveedor puede proyectarse como `watch` o `decision` según una política externa, sin que este adapter expanda autoridad.

## Scope y provenance

- Venture scope: `controlbot:venture/<venture_id>`.
- Signal ref: se convierte en una referencia determinista bajo `controlbot:vendor-exception/`.
- Source ref: se hashea bajo `controlbot:vendor-exception/source/`.
- Evidence refs: se hashean bajo `controlbot:evidence/vendor/`.

No se copian credentials, owner identity refs ni payloads de proveedor.

Freshness se traduce:

- `fresh -> current`
- `stale -> stale`
- `unknown -> unknown`

Cuando freshness es unknown, el bridge elimina source/evidence/observed_at para respetar el fail-closed de Owner Inbox.

## Límites

No clasifica prioridad, no crea Owner Decisions, no compra/renueva/offboardea proveedores, no crea Factory WorkItems, no llama providers y no persiste estado.
