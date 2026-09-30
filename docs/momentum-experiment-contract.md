# MOMENTUM Experiment Core

#426 define el contrato puro de experimentación de MOMENTUM. Reutiliza Campaign
de #228 y sus creative refs; no ejecuta tráfico, tracking, providers ni gasto.

## Contrato

Un experimento queda ligado a un Venture y Campaign canónicos. Antes de
`ready|running|completed` exige hypothesis, baseline, variantes, métrica y una
ventana válida. Baseline y variantes deben pertenecer a la Campaign y no pueden
duplicarse.

El resultado conserva `observed | inferred | unknown`, freshness y evidencia.
`unknown` no puede declarar winner, effect, source ni evidencia. Un resultado
observed/inferred requiere source, observed_at, freshness conocida y evidence.

## Límites

La capa es determinista y read-only: `execution=false`. No asigna tráfico, no
elige ganador, no infiere causalidad, no publica creative, no consume budget y
no crea WorkItems. Provider delivery y medición real permanecen fuera del core.

Reversión: retirar estas cuatro rutas; no existe persistencia ni estado externo.
