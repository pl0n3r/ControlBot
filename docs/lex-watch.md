# LEX Legal Watch v1

## Propósito

Legal Watch normaliza señales regulatorias verificables y fechadas para LEX. Es un contrato **read-only**: no navega la web, no ejecuta providers, no abre trabajo automáticamente y no emite asesoría ni conclusiones jurídicas.

## Estados de fuente y freshness

Cada señal declara:

- `source_state: verified|rumor|unknown`;
- `freshness: fresh|stale|unknown`;
- `published_at`, `effective_at` y `observed_at`;
- jurisdicción y refs de norma/cambio;
- source/evidence refs;
- `impact_state: plausible|none|unknown` e impact refs.

Solo una fuente `verified + fresh` puede producir `publication_state=published`. Rumor, fuente unknown o evidencia stale/unknown producen `publication_state=unknown` y `effective_state=unknown`.

`observed_at` y `published_at` no pueden estar en el futuro respecto del instante de evaluación. Una fecha efectiva sí puede ser futura: en ese caso el resultado es `not_yet_effective`, nunca `effective`.

## Publicación no equivale a vigencia

Legal Watch separa explícitamente:

- `publication_state: published|unknown`;
- `effective_state: effective|not_yet_effective|unknown`.

Una señal publicada con fecha efectiva futura puede justificar revisión preventiva, pero no se presenta como vigente.

## Dedupe

Señales equivalentes se deduplican por:

`jurisdiction + regulation_ref + change_ref + impact_state + impact_refs`.

Los IDs de señal, source refs y evidence refs se unen y ordenan. Si dos entradas con la misma identidad semántica contradicen estado de fuente, fechas, freshness o estado derivado, el contrato falla cerrado en vez de elegir una versión.

## Review candidates

Un candidato de revisión existe solo cuando:

1. la fuente es `verified`;
2. freshness es `fresh`;
3. existe publicación verificable;
4. `impact_state=plausible`;
5. existen `impact_refs` trazables.

El candidato conserva source/evidence refs, `source_state`, `freshness`, `published_at`, `effective_at`, `observed_at` y los estados derivados de publicación/vigencia. No contiene `compliant`, aprobación, recomendación jurídica ni autoridad para ejecutar trabajo. Los slices posteriores deciden si una señal trazable requiere reevaluación, human gate o WorkItem Factory.

Rumor, UNKNOWN, stale, impacto `none` o impacto `unknown` nunca se convierten en hecho vigente ni candidato ejecutable.

## Seguridad y minimización

El esquema es cerrado. IDs, jurisdicciones y refs están acotados y rechazan patrones de secretos/credenciales. Legal Watch conserva referencias, no documentos jurídicos completos ni PII innecesaria.

## Fuera de alcance

No incluye crawler o monitor web real, providers, notificaciones externas, UI, interpretación jurídica, apertura automática de Issues/WorkItems, cambios de authority ni go-live.
