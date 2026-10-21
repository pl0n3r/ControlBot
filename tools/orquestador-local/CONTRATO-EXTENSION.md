# Control de agentes AutoFactory desde el orquestador

El orquestador escucha en la red local (0.0.0.0:8777). Desde otra computadora solo se aceptan
`/api/af`, `/api/af/commands` y `/api/af/ack`, y siempre con la cabecera `X-Agent-Key`
(clave en `agent_key.txt`). El dashboard y las acciones de GitHub siguen siendo solo del Mac.

Base remota: `http://<IP-del-Mac>:8777`

1. Latido (cada ~30 s): `POST /api/af` con el estado y los chats (formato actual).
2. Órdenes: `GET /api/af/commands?instance_id=<id>` -> `{"commands":[{"id":N,"chat_id":null|"c1","want":"stop"|"start"}]}`
   `chat_id` null = todo el agente; si no, solo ese chat.
3. Confirmar: `POST /api/af/ack` con `{"instance_id":"<id>","id":N}` tras aplicar la orden.

Todas las llamadas llevan `Origin` de la extensión y `X-Agent-Key`. Sin contenido de chats.
