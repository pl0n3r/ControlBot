# ControlBot · Interruptor propio del panel — contrato de ensayo #1363

**Estado: NO CONECTADO · SOLO PRUEBAS SINTÉTICAS · NO-GO.**
Esta entrega de construcción modifica exclusivamente `tests/test_panel_kill_switch.py` y
`docs/panel-kill-switch.md`: **no instala** un interruptor HTTP en PHP, no revoca
sesiones ni permisos reales, no cambia Hostinger y no habilita publicación. El panel
productivo no queda protegido por este documento ni por el simulador de pruebas.

## Autoridad y separación

- **ControlBot** necesita un estado propio y persistente de apagado que afecte
  toda ruta y proceso de escritura del panel.
- **Factory #767** controla agentes; ponerlo en `PAUSED` no cierra ControlBot,
  sus sesiones, cron, puentes o credenciales.
- Solo el OWNER autenticado y con MFA reciente puede solicitar `stop`,
  reintentar una revocación fallida o confirmar explícitamente `resume`.
- El operador aporta un `reason_code` canónico de incidente y fecha UTC,
  auditables, sin secretos ni datos personales en texto libre.

## Máquina de estados requerida para integración posterior

| Estado | GET autorizado | Mutaciones | Sesiones y grants | Recuperación |
| --- | --- | --- | --- | --- |
| `normal` | Autenticado | Según autorización vigente | Vigentes | No aplica |
| `read_only` con revocación completa | Nueva sesión autenticada | **Denegadas todas** | Previas revocadas | Solo OWNER + MFA + confirmación explícita |
| `read_only` con `revocation_pending` | **Denegado por seguridad** | **Denegadas todas** | Desconocido hasta recibo | Reintentar revocación y verificar recibo |

1. La transición de apagado instala primero la prohibición de mutaciones.
   Solo después intenta revocar sesiones y accesos. Si falla Identity Center,
   la aplicación permanece cerrada (`revocation_pending`), nunca declara éxito.
2. Revocar las sesiones anteriores implica que **reactivar no restaura**
   contraseñas, sesiones ni grants: la siguiente sesión exige autenticación nueva.
3. Los reintentos idempotentes no duplican revocaciones; el diario es append-only
   con actor, timestamp, código de motivo, resultado y recuentos, sin payloads.
4. Una reactivación exige confirmación diferenciada y revocaciones completadas,
   sin cron automático ni timeout que reanude por sí mismo.
5. Toda ruta de escritura **incluidas** APIs, procesos asíncronos y acciones del
   OWNER debe consultar una puerta durable común antes del primer efecto.

## Criterios ejecutables con cuentas falsas

- `tests/test_panel_kill_switch.py::PanelKillSwitchTests::test_criterio_principal`:
  cortar sesiones y grants sintéticos, bloquear escrituras, permitir solo lectura
  de una nueva sesión autenticada y recuperar con autorización explícita.
- Negativos: no OWNER, MFA ausente, motivo malformado, marca temporal repetida,
  fallo del centro de identidades, reintento, operación desconocida, sin fuga de
  tokens ni sesiones en auditoría.
- La referencia histórica solicitada en el Issue
  `docs/panel-kill-switch.md::Panel-kill-switchTests::test_criterio_principal`
  es un identificador documental, no un selector ejecutable de unittest.
- Ejecución reproducible desde la raíz: `python3 -m unittest
  tests/test_panel_kill_switch.py -v` (o `python3 -m unittest discover -s tests
  -p 'test_panel_kill_switch.py' -v`).
- El contrato de aceptación de GitHub requiere además `CI Factory v1 / Validar`
  y los controles de revisión y seguridad sobre el HEAD exacto.

## Qué falta antes de afirmar que el panel tiene apagado real

La futura integración necesita **otra reserva V3 con claims explícitos** para
el controlador/entrypoint PHP, middleware central de todas las escrituras, sesiones,
Identity Center y el almacenamiento transaccional de estado/auditoría. La API real
de Identity Center actualmente es una máquina de comandos de dominio, **no** un
IdP ni revocador de credenciales o cookies. Sus grants y el
`CapabilityGrantRevoker` no forman un ledger durable inter-proceso. Requiere
adapter autenticado, revocación verificable, cierre de sesiones en distintos
workers y ensayos de recuperación/split-brain; no se simula esta evidencia.

**Gates:** D-069 mantiene exigencias de reserva, CI, hallazgos graves y fusión.
D-043/D-059, privacidad, prueba real de backup/restore y consentimiento del
OWNER siguen cerrados para go-live. Revertir el squash de #1363 retira solo
la documentación y las pruebas; no cambia producción.
