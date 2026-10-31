# ControlBot #1362 — inventario de perfiles y chats (solo lectura)

## Autoridad y estado de la evidencia

**Estado: NO_VERIFICADO / NO GO.** Este corte describe el esquema declarado en `datos.yml` y las reglas de minimización. **No es una lectura de datos vivos** ni prueba de que existan perfiles o conversaciones persistidos. La fase es `construccion`; el inventario de registros, la ubicación física, los permisos y la base jurídica requieren un operador autorizado y una fuente identificada. No se ha conectado Hostinger ni ninguna cuenta de ChatGPT, y no se ha copiado ningún mensaje. Todo recuento real queda **NO_VERIFICADO** hasta recibo primario.

## Inventario de categorías declaradas

La ubicación aquí es **lógica**, relativa a `datos.yml > treatments`; no identifica una base o archivo real. El valor `NO_VERIFICADO` nunca significa cero.

| Categoría lógica | Campos tipados declarados | Conteo de registros reales | Ubicación lógica; ubicación física | Decisión de minimización |
| --- | --- | --- | --- | --- |
| `account_profiles` | `account_alias`, `profile_alias`, `browser`, `plan` | `NO_VERIFICADO` | `datos.yml` / `account_profiles`; física `NO_VERIFICADO` | Conservar solo alias seudónimos indispensables; browser/plan sujetos a finalidad. No mover contraseñas ni sesiones. |
| `agent_status` | `agent_id`, `account_alias`, `profile_alias`, `tab_id`, `status`, `last_heartbeat_at`, `mode`, `repository`, `issue_number` | `NO_VERIFICADO` | `datos.yml` / `agent_status`; física `NO_VERIFICADO` | Conservar estados derivados solo si son necesarios; excluir identificadores efímeros caducados. |
| `chat_response_opt_in` | `agent_id`, `tab_id`, `repository`, `issue_number`, `question_text`, `response_text`, `status`, `received_at`, `opt_in` | `NO_VERIFICADO` | `datos.yml` / `chat_response_opt_in`; física `NO_VERIFICADO` | **NO_MIGRAR por defecto** contenido `question_text` y `response_text`. Excepción requiere aprobación, base jurídica y `opt_in` verificado; de otro modo solo métricas agregadas. |

Los campos son nombres de esquema, no valores reales. Este catálogo **no acredita que haya chats guardados**, ni dónde residan. No registra usuarios, IDs de cuentas reales, rutas privadas, mensajes, cookies, tokens, contraseñas o contactos.

## Procedimiento de inventario real pendiente de autorización

1. **Preflight del dueño:** identificar titular, finalidad, fuente autenticada, autorización explícita, base jurídica/consentimiento, retención, necesidad, ámbito, destino y operador; guardar el recibo por un canal privado. Sin el recibo mantener `NO_VERIFICADO` y no consultar fuentes.
2. **Lectura read-only:** obtener solo conteos agregados por categoría, tipos y ubicación general, en fuente aprobada con permisos mínimos y corte temporal. Prohibido extraer texto de chats, nombres, cookies, claves o contenido personal; no incluir payloads en logs o Issues.
3. **Manifest privado:** registrar alias de fuente y entorno, versión/SHA, fecha de corte, conteos, versión de esquema, alcance y fingerprint de metadatos. Nunca subir manifiesto sensible a GitHub. Todo dato ausente queda `NO_VERIFICADO`.
4. **Minimización:** separar alias necesarios, browser/plan opcionales, IDs efímeros y contenido de chats. Decisión inicial **NO_MIGRAR** para conversaciones. Cualquier excepción exige aprobación del dueño y prueba de derechos.

## Plan reversible de migración, NO EJECUTADO

- **G0 — autoridad (NO GO):** aprobación específica, consentimiento/base, origen y destino, backup restaurable, integridad y rollback ensayado (D-043/D-059). Un CI verde o fixture no satisfacen la puerta.
- **G1 — snapshot:** contar filas elegibles en la fuente autorizada y anotar SHA de esquema, sin leer texto. Conteo desconocido o inconsistencias = `NO GO`.
- **G2 — dry-run sintético:** mapear alias opacos, detectar duplicados, colisiones y retención sin escrituras. Idempotencia por identificador opaco; no trasladar sesiones.
- **G3 — destino aislado:** solo tras aprobación adicional, backup verificado y permisos vigentes podría efectuarse una copia aislada. **No se ejecuta en #1362.**
- **G4 — reconciliación de filas:** por categoría comparar `origen_elegible`, `destino_insertado`, `destino_rechazado` y `duplicados`. Exigir `origen_elegible = destino_insertado + destino_rechazado + duplicados`, **cero rechazados y duplicados inesperados**, unicidad e integridad. Cualquier diferencia, hash inconsistente o recibo ausente mantiene `NO GO`.
- **G5 — rollback / aprobación separada:** restaurar destino aislado tras un fallo; no borrar origen. Go-live, Hostinger y migración efectiva requieren decisión posterior y evidencia terminal exact-SHA `VALIDATED_IN_PRODUCTION`, no esta guía.

## Pruebas y reversión

`python3 -m unittest tests.test_profiles_chats_inventory -v` comprueba categorías y campos desde `datos.yml`, conteos desconocidos y puertas fail-closed. Los ejemplos de reconciliación son **sintéticos**, no una migración verificada. El check `CI Factory v1 / Validar` debe pasar sobre HEAD exacto. Revertir el squash elimina guía y test; no modifica despliegues, datos, permisos ni perfiles.