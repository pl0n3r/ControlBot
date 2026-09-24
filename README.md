# ControlBot

Centro de control web de la fábrica de software de pl0n3r. Una herramienta **separada** de los productos (Condor, GrindFlow, BRVTAL) y del kit (`pl0n3r/factory`).

## Qué hace

- **Dashboard:** estado de producción, trabajo, seguridad y costos de todos los proyectos.
- **Agentes:** cada agente de ChatGPT web (navegador + perfil + cuenta + pestaña), con latido, estado y botón de pausa.
- **Chat:** hablar con cada agente desde aquí; el mensaje llega verificado a su pestaña.
- **Despacho:** asignar trabajo o dejar que el despachador reparta según la capacidad de cada cuenta.
- **Decisiones:** responder con un clic lo que solo decide el dueño.
- **Nuevo proyecto:** crear un repo nuevo gobernado por factory.
- **Cuentas y perfiles:** agregar y administrar cada vez más cuentas de ChatGPT, cada una en su perfil de navegador.
- **Bitácora:** todo auditado.

Especificación completa: [#1](https://github.com/pl0n3r/ControlBot/issues/1). Lado de la extensión: [pl0n3r/AutoFactory#1](https://github.com/pl0n3r/AutoFactory/issues/1).

## Despliegue

Hostinger, junto a Condor pero separado de su código y su base de datos: `https://control.condorapp.com.co`. PHP 8.5 + MariaDB propia; el puente con los navegadores usa HTTPS (latido y long-polling). Acceso solo del dueño.

## Cómo trabajan los agentes aquí

Igual que en el resto de la fábrica: leen [PLAN-AGENTES.md](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md) y después [AGENTES.md](AGENTES.md).
