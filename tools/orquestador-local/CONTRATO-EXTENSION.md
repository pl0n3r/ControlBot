# Control de agentes AutoFactory desde el orquestador

El orquestador escucha en la red local (0.0.0.0:8777). Desde otra computadora solo se aceptan
`/api/af`, `/api/af/commands`, `/api/af/ack` y `/api/af/log`, y siempre con la cabecera `X-Agent-Key`
(clave en `agent_key.txt`). El dashboard y las acciones de GitHub siguen siendo solo del Mac.

Base remota: `http://<IP-del-Mac>:8777`

1. Latido (cada ~30 s): `POST /api/af` con el estado y los chats (formato actual).
2. Órdenes: `GET /api/af/commands?instance_id=<id>` -> `{"commands":[{"id":N,"chat_id":null|"c1","want":"stop"|"start"|"assign"}]}`
   `chat_id` null = todo el agente; si no, solo ese chat. Con `want: "assign"`, el registro incluye `repo` o `issue`, según la asignación.
3. Confirmar: `POST /api/af/ack` con `{"instance_id":"<id>","id":N}` tras aplicar la orden.
4. Diagnóstico minimizado: `POST /api/af/log` con `{"instance_id":"<id>","entries":[...]}` (máximo 50 entradas de hasta 1.200 caracteres cada una), sin texto de conversaciones ni credenciales.

Todas las llamadas llevan `Origin` de la extensión y `X-Agent-Key`. Sin contenido de chats.
