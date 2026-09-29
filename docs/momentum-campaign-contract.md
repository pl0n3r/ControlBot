# MOMENTUM Campaign Contract

Este contrato es el primer slice ejecutable de #126. Define identidad de marca y campañas por Venture sin acoplar el dominio a proveedores de paid media, mensajería o social publishing.

## Brand Context

Cada `BrandContext` pertenece exactamente a un `venture_id` y contiene únicamente referencias opacas:
- `tone_ref`;
- constraints versionables;
- `source_ref`;
- `observed_at`.

No guarda assets binarios, prompts, usuarios, credenciales ni secretos.

## Campaign

`Campaign` enlaza:
- Venture + BrandContext;
- objective;
- audience/offer/CTA refs;
- canales neutrales;
- creative variant refs;
- budget ref de CAPITAL;
- schedule;
- experiment refs;
- evidence refs;
- status.

Los canales del core son categorías neutrales: `organic_social`, `paid_social`, `search_ads`, e&#109;ail, `web` y `other`. Un provider concreto será un adapter posterior y no modifica este contrato.

## Aislamiento

Una Campaign solo puede usar el BrandContext del mismo Venture. El contrato no resuelve ni duplica Ventures: consume sus IDs canónicos como referencias. Market Scope #162 permanece independiente; `audience_ref` es opaca hasta que exista el contrato de mercados correspondiente.

## Privacidad y autoridad

Las refs sensibles al dominio usan namespaces canónicos (`audience:`, `offer:`, `cta:`, `creative:`, `budget:`, `experiment:`, `evidence:`) y un identificador opaco verificable de 32 caracteres hexadecimales minúsculos. Nombres, teléfonos, IDs semánticos, namespaces incorrectos y material con forma de password/token/secret/cookie/API key fallan cerrado. Datos CRM, listas de contactos y usuarios finales permanecen en su fuente autoritativa.

`execution=false` es obligatorio. Este slice no publica, no gasta, no llama providers, no crea scheduler y no materializa Factory WorkItems. Los gates de budget/authority y la distribución por adapters pertenecen a slices posteriores.

## Determinismo

Listas de canales, variants, experiments, evidence y constraints se normalizan de forma ordenada y sin duplicados. Campos desconocidos, schedules incoherentes, estados inválidos y mezclas cross-Venture fallan cerrado. Una campaña `completed` requiere tanto `start_at` como `end_at`.
