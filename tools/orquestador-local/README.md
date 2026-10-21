# Orquestador local — implementación de referencia

> Prototipo que dirige a los chats de la fábrica (ChatGPT web + extensión AutoFactory) y vigila los repos.
> **Es código de referencia para el Scheduler V1 de ControlBot, no un componente de producción: no se despliega.**

## Qué hace

- Lee GitHub (Issues, PRs, checks, releases) con la CLI `gh` y arma un panel en `http://127.0.0.1:8777`.
- **Reparte trabajo** a los chats conectados (protocolo `/api/af` de AutoFactory, ver `CONTRATO-EXTENSION.md`): prioridades, topes, desbloqueo, recuperación de reservas, apartado de issues estancados.
- Renueva reservas con `/tomar`, encola la fusión automática de PRs listos y ordena la cola de revisiones de CodeRabbit.
- Muestra decisiones del dueño con botones que publican `/decidir A|B`, y un botón para detener o iniciar el reparto.

Las reglas están descritas, con su porqué, en `docs/orquestador/scheduler-v1-reglas.md` (hoja ControlBot #843).

## Cómo arrancarlo (solo desarrollo local)

```bash
cd tools/orquestador-local
cp config.example.json config.json      # metas y sitios a vigilar (opcional)
python3 orq.py 8777                     # panel en http://127.0.0.1:8777
```

Requiere `python3`, la CLI `gh` autenticada y, para dirigir chats, la extensión AutoFactory conectada. La primera ejecución crea `agent_key.txt` (clave de los agentes; **nunca se versiona**).

`vigia.sh` reinicia el orquestador si deja de responder; combínalo con `caffeinate -dimsu` en macOS para que no se duerma.

## Archivos de estado (ignorados por git)

`acciones.log`, `af_logs.jsonl`, `agent_key.txt`, `assigned.json`, `agent_blocked.json`, `planner_state.json` y `config.json` se generan al ejecutar y no deben versionarse.

## Límites conocidos

Es un único archivo de ~1.700 líneas, sin pruebas unitarias del planificador, con parches locales acumulados. Al portarlo a ControlBot (PHP + MariaDB) conviene convertir el planificador en una función pura (estado de GitHub y de chats → órdenes) con pruebas offline.
