# Product Discovery Core v1

Contrato puro para expresar una iniciativa y su hipótesis antes de ejecutar experimentos o materializar trabajo. El objetivo es conservar problema, segmento, evidencia y provenance sin exigir repositorio ni convertir inferencias en decisiones.

## Límite del dominio

ProductDiscovery::initiative() normaliza una iniciativa con scope group|venture. Un scope Venture exige venture_id; Group lo prohíbe. market_ref es opcional. Problema, segmento, evidencia, source y responsable se almacenan solo como referencias opacas namespace:<32 hex>.

ProductDiscovery::hypothesis() se liga a una iniciativa por initiative_id y exige expected_outcome_ref y primary_metric_ref. Constraints y evidence refs son listas cerradas, sin duplicados y ordenadas determinísticamente. La hipótesis conserva su propio source, freshness y confidence.

## Semántica fail-closed

- Estados de iniciativa: IDEA, RESEARCHING, HYPOTHESIS, PARKED.
- Freshness: fresh, stale, unknown.
- Confidence: low, medium, high, unknown.
- Freshness unknown exige confidence unknown.
- Evidencia stale nunca puede declarar confidence high.
- Una iniciativa puede empezar sin evidencia y sin repo/project. Una hipótesis material requiere al menos una evidence ref.
- Campos extra, referencias no opacas y listas duplicadas fallan cerrado.

## Responsabilidades y dependencias

Este slice consume el lenguaje de Market Scope mediante una referencia opcional, sin duplicar su geografía ni su lifecycle. Mantiene solo contratos de datos deterministas. El runner de experimentos, resultados, decision gate y cualquier materialización de trabajo pertenecen a slices posteriores.

Alternativa descartada: incluir ejecución o scoring dentro del core. Se evita porque mezclaría evidencia con autoridad, ampliaría el blast radius y dificultaría distinguir observación de decisión.

## Verificación

Los escenarios PHP y tests/test_product_discovery.py cubren los cinco criterios del Issue #240: iniciativa sin repo, binding de hipótesis, freshness/confidence fail-closed, refs/listas cerradas y ausencia de efectos operativos.
