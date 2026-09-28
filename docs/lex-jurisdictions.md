# LEX Jurisdiction Packs v1

## Límite

`LexJurisdiction` valida paquetes versionados de evidencia normativa. No interpreta leyes, no declara que una empresa cumpla una obligación y no convierte la existencia de un pack en autorización para operar en un mercado.

El core es independiente del país: acepta scopes `country:XX`, `region:...` y `supranational:...`. Colombia vive únicamente como datos en `config/lex/jurisdictions/CO.json`.

## Contrato de pack

Un pack declara versión de esquema y pack, jurisdicción, tipo, estado, freshness, revisión/vencimiento, responsables, fuentes, controles, supuestos, exclusiones y compatibilidad con LEX Core.

`evidence_state=current` significa únicamente que la metadata del pack está activa, fresh, revisada y no expirada para el contrato técnico. No significa vigencia jurídica certificada ni cumplimiento del Venture/Project.

`stale`, `unknown`, expiración o incompatibilidad con el contrato del core producen `evidence_state=not_current` y razones explícitas.

## Sources y controles

Las fuentes son referencias HTTPS a autoridades/documentos verificables. Los controles apuntan a `source_id` existentes y a un `requirement_ref` interno. El texto de una norma no se copia al pack y el runtime no deriva asesoría jurídica desde el título de una fuente.

CO v1 usa como provenance inicial:

- SIC — Ley Estatutaria 1581 de 2012: https://sedeelectronica.sic.gov.co/transparencia/normativa/ley-estatutaria-1581-de-2012
- Función Pública — Decreto 1074 de 2015: https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=76608
- Función Pública — Ley 1480 de 2011: https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=44306
- Función Pública — Ley 2439 de 2024: https://www1.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=257116

Estas referencias fueron revisadas al construir el pack; cada control mantiene `human_review_required=true` porque su aplicabilidad concreta depende del Venture, operación, datos y relación jurídica.

## Extensibilidad

El escenario sintético `country:MX` demuestra que un segundo pack usa el mismo contrato sin modificar `LexCore` ni introducir branching por país. Es un fixture técnico, no un pack jurídico de México.

## Seguridad

El esquema es cerrado; IDs/refs filtran patrones sensibles, las URLs deben ser HTTPS sin credenciales embebidas, las fuentes y controles se deduplican por identidad y timestamps de revisión futuros se rechazan.

## Fuera de alcance

No Legal Watch, scraping, interpretación jurídica, Market activation, Factory handoff, UI, providers ni datos reales de clientes.
