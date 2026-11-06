# Guía del propietario de ControlBot

**Estado de esta guía:** noviembre de 2026. Describe pantallas preparadas en el código de ControlBot, **no una instalación en producción comprobada**. Algunas vistas necesitan datos confiables o una configuración privada antes de funcionar. Una pantalla vacía o un aviso de falta de información nunca significa que todo esté bien.

## 1. Entrar y salir del panel

### Para qué sirve
Proteger el acceso a la información y a las decisiones del propietario.

### Antes de empezar
La página de acceso debe haber sido habilitada por quien administra el servidor. Necesitas tu cuenta, tu contraseña y, si está activada, la verificación en dos pasos. No compartas estos datos.

### Cómo se usa (paso a paso)
1. Abre /login y busca «Acceso del propietario».
2. Completa «Usuario» y «Contraseña». Si activaste la verificación en dos pasos, introduce también el «Código de verificación».
3. Selecciona «Entrar». Cuando aparezca «Sesión iniciada», usa «Ir al panel».
4. Al terminar, vuelve a la página de acceso y pulsa «Salir», si tu sesión muestra esa opción.

### Avisos y mensajes
«Usuario o contraseña incorrectos.» indica que el acceso no fue aceptado. No pruebes contraseñas repetidamente: el sistema limita los intentos.

### Limitaciones
Este acceso con contraseña se habilita solo con una configuración privada. No reemplaza los permisos adicionales exigidos en las pantallas de decisiones y control. La existencia del formulario no demuestra que el sitio público esté habilitado.

## 2. Ver el estado general de la fábrica

### Para qué sirve
Consultar el trabajo observado en los distintos proyectos y reconocer dónde hace falta tu atención.

### Antes de empezar
Entra con una sesión autorizada. Los datos dependen de registros válidos; si faltan, el panel muestra incertidumbre.

### Cómo se usa (paso a paso)
1. Abre la portada o /overview y localiza «Orquestador en vivo».
2. Usa «Repositorio» para centrarte en un proyecto y «Tipo / estado» para limitar los frentes visibles. «Todos» muestra el conjunto.
3. Abre «Humano» o busca «Decisiones tuyas» para localizar asuntos que requieren intervención.
4. Consulta «Eventos observados» y, cuando aparezca, «Abrir Issue» para examinar la evidencia en GitHub.

### Avisos y mensajes
«Sin frentes activos.» indica que no hay frentes representados en esa vista. «Kill switch: UNKNOWN» advierte que no hay confirmación suficiente sobre ese control.

### Limitaciones
«Orquestador en vivo» es el nombre de la pantalla, no una garantía de conexión permanente. La vista es de consulta: no ordena trabajo por sí sola ni autoriza cambios. Un estado sin pruebas suficientes debe tratarse como desconocido.

## 3. Revisar la información de GitHub

### Para qué sirve
Ver el estado documentado de los proyectos, sus repositorios y la evidencia disponible sin modificarla.

### Antes de empezar
Necesitas acceso al panel. Los registros mostrados pueden estar incompletos o desactualizados.

### Cómo se usa (paso a paso)
1. Abre /github y busca «Evidencia GitHub por proyecto».
2. Lee cada proyecto y sus repositorios, junto con las señales de estado y antigüedad.
3. En la ficha de un repositorio utiliza «abrir evidencia» para revisar la fuente original.
4. Si conoces la dirección interna de un proyecto, su página de detalle se encuentra en /projects/nombre-del-proyecto/github. El nombre debe corresponder a un proyecto registrado.

### Avisos y mensajes
«Sin evidencia de repositorios.» o «No hay proyectos GitHub disponibles.» significa que esa vista no dispone de información suficiente. En el detalle, «UNKNOWN / STALE» advierte sobre información desconocida o antigua.

### Limitaciones
Son páginas de consulta. Una indicación favorable no sustituye revisar la fuente ni concede permiso para fusionar cambios.

## 4. Consultar decisiones pendientes

### Para qué sirve
Encontrar asuntos del propietario en un solo lugar y consultar su historial.

### Antes de empezar
Se requiere una sesión del propietario con las verificaciones exigidas por el sistema. También debe existir una fuente privada y verificada de decisiones.

### Cómo se usa (paso a paso)
1. Entra a /inbox y localiza «Inbox del dueño».
2. Elige «Todas», «Decisiones» o «Críticas» para revisar el tipo de asunto.
3. Selecciona el título de una decisión para abrir su detalle.
4. Consulta «Historial» cuando el registro esté disponible.

### Avisos y mensajes
«No hay decisiones pendientes para este filtro.» solo describe el resultado del filtro actual. «Historial no disponible; no se infiere ausencia de decisiones.» indica que no hay un historial verificable.

### Limitaciones
La página está construida, pero su acceso publicado todavía no conecta una fuente privada de decisiones. Puede mostrar «No disponible» en lugar de elementos; no interpretes eso como una bandeja vacía.

## 5. Aprobar o rechazar una decisión

### Para qué sirve
Registrar una respuesta del propietario a un asunto identificado.

### Antes de empezar
Abre un detalle válido desde la bandeja y lee las opciones. Esta función necesita autenticación reforzada y una fuente confiable que confirme el resultado.

### Cómo se usa (paso a paso)
1. En /decisiones/identificador revisa el título, las opciones y el contexto.
2. Usa «Aprobar» o «Rechazar» según corresponda, únicamente cuando el formulario esté disponible y la información sea suficiente.
3. Comprueba que aparezca «Decisión registrada». Después puedes elegir «Volver al Inbox».
4. Si no existe confirmación, considera la operación pendiente y verifica su estado antes de intentar otra vez.

### Avisos y mensajes
«Sin permiso» señala que no se cumplen las condiciones de acceso. «No disponible» no confirma una respuesta registrada.

### Limitaciones
La sola pulsación de un botón no demuestra una decisión efectiva. En el arranque actual, la conexión privada necesaria no está acreditada; no se promete ejecución real.

## 6. Pausar y reanudar nuevas asignaciones

### Para qué sirve
Pedir que se detenga o reanude la entrega de trabajo nuevo a los agentes, sin interrumpir por la fuerza lo que ya está en curso.

### Antes de empezar
La pantalla /pausa exige la sesión reforzada del propietario. La operación necesita una conexión confiable con el control de asignaciones.

### Cómo se usa (paso a paso)
1. Abre /pausa y comprueba el estado mostrado.
2. Para detener nuevas asignaciones, explica la causa en «Motivo» y pulsa «Pausar todos».
3. Lee «¿Pausar toda asignación nueva?» y confirma solamente si eso es lo que deseas.
4. Para permitir asignaciones de nuevo, pulsa «Reanudar» y revisa «¿Reanudar asignaciones?».
5. Comprueba el resultado: «Todos pausados» o «Asignación habilitada».

### Avisos y mensajes
«Consultando estado…» indica una lectura en curso. «Estado no disponible» o «No se acreditó el cambio; estado sin confirmar» significa que no hay prueba de que la orden se haya aplicado.

### Limitaciones
Una pausa no cancela tareas ya iniciadas. Sin una conexión operativa confiable, la pantalla no puede confirmar cambios. El acceso por contraseña local, por sí solo, no permite operar este control.

## 7. Entender los respaldos y la recuperación

### Para qué sirve
Conocer qué se necesita para recuperar la información tras una falla. Un respaldo es una copia protegida; recuperar significa comprobar que esa copia sirve para volver a disponer de la información.

### Antes de empezar
La página /security/resilience requiere acceso reforzado. La información sobre respaldos no debe confundirse con la existencia de copias verificadas.

### Cómo se usa (paso a paso)
1. Abre la vista «Recuperación ante desastres».
2. Busca «Estado por proyecto» para revisar la información de cada proyecto.
3. Consulta los apartados «Copia de base de datos», «Copia externa», «Copia inmutable» y «Versionado de medios».
4. Si aparece «UNKNOWN», solicita evidencia de una copia comprobada y de una prueba de recuperación antes de confiar en ella.

### Avisos y mensajes
«UNKNOWN» indica ausencia de confirmación. No prueba que exista una copia ni que sea posible recuperar los datos.

### Limitaciones
Esta pantalla es de consulta. No crea copias, no restaura información y no permite borrar respaldos. Las pruebas y la habilitación real de respaldos siguen pendientes de las autorizaciones y comprobaciones correspondientes.

## 8. Revisar asuntos legales y privacidad

### Para qué sirve
Conocer qué documentos y datos del responsable aún requieren comprobación o aprobación.

### Antes de empezar
Ingresa al panel como propietario. La información depende de documentos y registros verificables.

### Cómo se usa (paso a paso)
1. Abre /legal y busca «Legal y privacidad».
2. Consulta «Documentos», si el proyecto cuenta con información, y revisa los estados mostrados.
3. Examina «Datos del responsable» para reconocer la información pendiente.
4. Revisa «Puertas jurídicas abiertas» cuando el panel muestre asuntos por resolver.

### Avisos y mensajes
«UNKNOWN · fuente no disponible» significa que no existe información verificable para esa parte. «BORRADOR pendiente de aprobación» señala un texto que todavía no debe presentarse como vigente.

### Limitaciones
Esta pantalla no aprueba documentos ni sustituye asesoría legal. Un documento cargado no se vuelve automáticamente válido.

## 9. Qué aún no debes dar por habilitado

### Para qué sirve
Evitar confundir funcionalidades previstas, pruebas de laboratorio y servicios realmente disponibles.

### Antes de empezar
Distingue entre una pantalla construida, una fuente de información conectada y un sitio listo para operar.

### Cómo se usa (paso a paso)
1. Verifica primero que la pantalla exista y que puedas entrar de forma autorizada.
2. Si falta información, no la reemplaces por una suposición favorable.
3. Para decisiones, pausas y respaldos, exige una confirmación verificable de la acción o de la copia.
4. Antes de operar con información real, comprueba que se hayan autorizado y probado acceso, resguardo de claves, respaldos y recuperación.

### Avisos y mensajes
«UNKNOWN» y «No disponible» son avisos de falta de evidencia, no de éxito.

### Limitaciones
Las páginas de agentes y de observabilidad ampliada están en trabajos separados que todavía no están fusionados en esta versión; no se describen aquí como pantallas disponibles. Tampoco se acredita un despliegue productivo, ni respaldos reales, ni el funcionamiento de las conexiones privadas de decisiones. No guardes claves en el manual ni en capturas compartidas.
