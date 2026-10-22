# Scheduler V1 — reglas extraídas del orquestador local

> Especificación descriptiva para ControlBot #843, derivada de la referencia fusionada en ControlBot #842. **No es autorización de despliegue, ni implementación del scheduler PHP/MariaDB**.

## Alcance, fuentes y precedencia

- Fuente ejecutable: [tools/orquestador-local/orq.py](../../tools/orquestador-local/orq.py), importado por [#842/PR #844](https://github.com/pl0n3r/ControlBot/pull/844). Contexto: [README del prototipo](../../tools/orquestador-local/README.md) y [contrato AutoFactory](../../tools/orquestador-local/CONTRATO-EXTENSION.md).
- Los valores abajo reflejan condiciones que ejecuta el código, no frases de comentarios contradictorios. Cada regla documenta motivo, fuente y diferencia con la autoridad normativa cuando aplica.
- La precedencia de seguridad es PLAN-AGENTES.md, kill switch Factory #767, reserva V3, claims, gates D-069/D-070, privacidad y decisiones del dueño; nunca la heurística local de una pantalla.

## R01 — Prioridad de candidatos

**Regla:** candidates() ordena recuperación (clase 0), PR en revisión que requieren reparación (0.5), disponibles (1), borradores limpios esperando revisión (1.6) y alertas automáticas (2). Ordena antigüedad dentro de una clase, excluye claims/dependencias incompatibles y pospone nuevas hojas del repositorio con recuperaciones.

**Por qué:** La continuidad y el desbloqueo técnico pesan más que abrir trabajo o reenviar un borrador sin cambio de estado.

**Fuente:** [orq.py#L875-L916](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L875-L916).

## R02 — Cupos de reparto

**Regla:** PLANNER fija per_repo=12, max_active=99 y per_tick=8. plan_tick() limita un chat al issue actual y hasta dos aparcados (3 issues por chat); los límites del prototipo no sustituyen la exclusividad V3 ni el tope normativo del Plan.

**Por qué:** Controlar sobreasignación mientras varios chats procesan trabajos independientes sin apropiarse de rutas.

**Fuente:** [orq.py#L647-L648](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L647-L648).

## R03 — Chats ociosos y espera de 2 minutos

**Regla:** Sólo considera latidos de 180 s o menos; atiende antes chats dirigidos en esperando_envio. min_gap_s=120 (2 minutos) entre cambios, protección de 150 s para una asignación reciente, y 90 s para comando sin ACK. Inactividad de pestaña superior a 600 s puede liberar su asignación.

**Por qué:** Evitar dobles órdenes cuando la entrega/ACK y los latidos tardan en propagarse.

**Fuente:** [orq.py#L1067-L1153](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L1067-L1153).

## R04 — Trabajo aparcado y continuidad

**Regla:** chat_items() conserva el issue actual y los aparcados; si todos esperan algo externo, plan_tick() puede escoger otra hoja (máximo 3 por chat), reactivar la anterior ante cambio de fingerprint y descartar issues cerrados. La V3 propia ejecutable siempre conserva prioridad bajo Factory #1334.

**Por qué:** Usar capacidad ociosa sin perder el hilo ni convertir la espera de CI en abandono.

**Fuente:** [orq.py#L957-L975](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L957-L975).

## R05 — Reenvío con retroceso

**Regla:** En plan_tick(): backoff = min(900, 120 * 2**(n-1)); secuencia efectiva 2, 4, 8, 15 minutos. Desde n>=3 sin cambio de fingerprint se fuerza 3600 s (60 minutos). El docstring histórico dice 1,5/3/6/10 y el comentario dice 30 min: NO coinciden con el ejecutable.

**Por qué:** Reducir mensajes repetidos y cuota de agentes ante ausencia de novedades; no hacer polling de CI.

**Fuente:** [orq.py#L1232-L1240](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L1232-L1240).

## R06 — Apartar tras cuatro envíos

**Regla:** Si n>=4 y el fingerprint de GitHub permanece igual, marca el issue como apartado durante 3 horas (3 h); si el chat comunica bloqueado, puede apartarlo antes. No usar el enfriamiento como veto a reparar una V3 propia ejecutable.

**Por qué:** Evitar bucles de cuatro envíos sin cambios verificables y reintentar sólo con nueva evidencia.

**Fuente:** [orq.py#L1127-L1136](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L1127-L1136).

## R07 — Presupuesto de desbloqueo

**Regla:** unblock_tries_recent() mantiene ventana móvil de 6 horas; unblock_candidates() admite como máximo 2 intentos por issue bloqueado en esas 6 horas. Excluye roadmap, readiness, alertas y asuntos ya tomados/apartados.

**Por qué:** Distinguir bloqueos técnicos trabajables de puertas que requieren autoridad o nueva evidencia.

**Fuente:** [orq.py#L922-L945](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L922-L945).

## R08 — Dependencias y claims

**Regla:** unmet_deps() excluye hojas con depends_on abiertos; paths_collide() compara rutas/prefijos con trabajo reservado o en revisión. plan_tick() suelta un issue con dependencias abiertas si todavía carece de PR.

**Por qué:** No presentar como listo trabajo que se solapa con otra V3 o necesita la integración anterior.

**Fuente:** [orq.py#L859-L895](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L859-L895).

## R09 — Recuperación por /tomar

**Regla:** Para estado requiere recuperación, plan_tick() publica /tomar y omite títulos epic, roadmap, planificaci o planning. Valores ejecutables: máximo 10 por ciclo y cooldown 900 s (15 minutos) por issue. Comentario antiguo dice 3 por ciclo y 25 min: es obsoleto. La recuperación real depende del recibo V3 del coordinador.

**Por qué:** Recuperar frentes que bloquearían nuevas asignaciones sin capturar épicos ni fabricar reserva.

**Fuente:** [orq.py#L1013-L1041](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L1013-L1041).

## R10 — Métrica honesta de bloqueos

**Regla:** Separar contenedores roadmap/planificación, decisiones y puertas del dueño/live de los issues técnicos bloqueados; no sumar automáticamente cada señal como tarea estancada.

**Por qué:** Mostrar deuda de ejecución real sin inflar blockers por gestión de producto o decisiones humanas.

**Fuente:** [orq.py#L360-L476](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L360-L476).

## R11 — Último resultado de checks

**Regla:** latest_checks agrupa por name/context y selecciona el último startedAt/completedAt; fails y pend se calculan después de deduplicar. En el puerto se exige identidad de HEAD y ejecución terminal autenticada.

**Por qué:** Un rerun verde reemplaza al fallo histórico del mismo contexto, nunca a checks de otro commit.

**Fuente:** [orq.py#L410-L490](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L410-L490).

## R12 — Cola de CodeRabbit

**Regla:** REVIEW_ASKED indexa (repo, PR, HEAD), REVIEW_MIN_GAP_S = 65*60; review_queue_tick() prioriza mayor dependents_count y descarta conflictos, fallos, pendientes y cuota agotada. Regla superior: una única revisión FINAL cuando CI esté verde; jamás reviews intermedias.

**Por qué:** Preservar cuota compartida limitada y usarla primero donde más hojas desbloquea.

**Fuente:** [orq.py#L670-L785](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L670-L785).

## R13 — Fusión automática --auto --squash

**Regla:** auto_merge_ready() invoca gh pr merge --squash --auto para una fila marcada ready; el criterio local fails=0/CLEAN es insuficiente. D-069 exige V3, dependencias/claims, protección, exact-HEAD Validar, seguridad y autoridad de fase; ni --auto ni fails=0 dan permiso por sí solos.

**Por qué:** Dejar la serialización y la protección a GitHub sin transformar la heurística local en autoridad.

**Fuente:** [orq.py#L1645-L1659](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L1645-L1659).

## R14 — Presupuesto GitHub y caché

**Regla:** CACHE_SECONDS=300 s (TTL 300 s); si rate_limit.remaining <1200 y hay snapshot anterior, se conserva lectura cacheada con error visible. Fallos de un repo no invalidan el resto; evidencia obsoleta no permite merges.

**Por qué:** Reducir presión de API, mostrar degradación parcial y no confundir datos en caché con autorización fresca.

**Fuente:** [orq.py#L537-L610](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L537-L610).

## R15 — Botón de detener/iniciar reparto

**Regla:** planner_set(on) guarda el booleano en planner_state.json; POST /api/planner verifica origen y token; con on=false, plan_tick() no reparte. Este interruptor local no sustituye Factory #767 RUNNING/PAUSED.

**Por qué:** Permitir al dueño parar el despacho sin modificar el interruptor canónico ni cancelar procesos remotos.

**Fuente:** [orq.py#L647-L667](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L647-L667).

## R16 — Botones /decidir

**Regla:** parse_gate() distingue factory-human-gate de issues ordinarios; la acción protegida publica /decidir A o /decidir B sobre el issue elegido. El puerto debe volver a verificar identidad del dueño, autenticidad, contexto y SHA exacto cuando proceda.

**Por qué:** No convertir un clic de una pantalla ni el texto de un issue en una autorización humana inventada.

**Fuente:** [orq.py#L133-L164](https://github.com/pl0n3r/ControlBot/blob/main/tools/orquestador-local/orq.py#L133-L164).

## Qué NO portar

- No portar ThreadingHTTPServer, exposición LAN, acceso a gh del Mac, rutas de usuario, vigia.sh ni ficheros locales de estado como planner_state.json o agent_key.txt al servidor Hostinger.
- No copiar sin revisión el protocolo de credenciales de extensión, los endpoints ni permisos de ejecución; ControlBot exige sesión owner-only, 2FA, CSRF, auditoría, mínimos privilegios y políticas de datos.
- No convertir heurísticas locales de fails=0, --auto --squash, cooldowns o aparcamiento en bypass de V3, kill switch #767, D-043, D-059 o decisiones de live; tampoco portar datos reales o trazas de conversaciones.

## Preguntas abiertas

1. ¿Qué tablas/índices y transacciones MariaDB representarán asignaciones, aparcados, receipts V3 y ACK sin carreras entre agentes?
2. ¿Cómo respetará la selección del nuevo scheduler el orden global de Factory §0 sin crear un segundo despachador normativo?
3. ¿Qué reloj de freshness y binding a HEAD exacto evitará usar caché u observaciones parciales como permiso de merge?
4. ¿Cómo se conectarán AutoFactory y el control plane owner-only con autenticación/revocación, minimización de datos y ACK idempotente?
5. ¿Cómo se registrarán las decisiones /decidir y sus permisos/exact-SHA sin decidir automáticamente por el dueño?

## Criterio de aceptación

- AC-01: una sección R01–R16 por cada regla, con motivo y referencia al código; cobertura de constantes, discrepancias históricas, Qué NO portar, Preguntas abiertas y precedencia de seguridad.
- Comprobación: python3 -m unittest tests/test_scheduler_v1_rules_doc.py -v. No requiere red ni scheduler funcionando.
