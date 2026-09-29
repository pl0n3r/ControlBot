# Customer Success Core

Primer slice ejecutable de #185. ControlBot consume señales agregadas por Venture y no se convierte en CRM, helpdesk ni fuente de verdad de conversaciones.

## CustomerSuccessSnapshot

El snapshot contiene nueve dimensiones separadas:

- onboarding;
- activation;
- adoption;
- usage recency;
- support burden;
- reliability impact;
- satisfaction;
- renewal signal;
- churn risk.

No existe un health score global obligatorio. Cada dimensión conserva status, ref de valor, evidence ref, freshness, confidence y nature.

churn_risk es siempre inferred: su confidence expresa incertidumbre y nunca transforma riesgo en hecho observado.

## Freshness y unknown

Estados conocidos pueden ser fresh o stale. Un estado unknown no transporta valor, evidencia ni confidence positiva. El contrato nunca promueve stale/unknown a current.

## SupportSignal

SupportSignal contiene únicamente metadata agregada:

- category y severity;
- pattern ref;
- knowledge ref;
- resolution state;
- evidence/freshness;
- escalation ref opcional.

No transporta conversaciones, cuerpos de tickets, attachments ni identificadores humanos. Las referencias usan el formato namespace:<32hex>. El validador garantiza namespace y forma; la opacidad real y la ausencia de PII son obligación del productor, que no debe codificar nombres, teléfonos ni identificadores semánticos dentro del valor hexadecimal.

## Scope

Todo registro se valida contra un venture_id esperado. Una señal de otro Venture falla cerrado.

El handoff desde adquisición/CRM queda como referencia opaca hasta completar MOMENTUM Revenue #238. Product Intelligence #241 sigue siendo la fuente de métricas agregadas de producto; este contrato no las duplica.

## Límite operativo

Este core es puro y no implementa providers, persistencia, notificaciones, creación de WorkItems, scheduler ni ejecución. Un slice posterior puede convertir patrones repetidos en trabajo trazable a través de Factory Queue sin añadir una cola paralela.
