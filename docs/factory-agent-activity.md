# Actividad de agentes · ControlBot #754
La proyección **no infiere sesiones online ni que un agente esté trabajando**. Solo procesa evidencia de lectura GitHub: Issues abiertos, PRs abiertos y runs de Coordinación. `updated_at` puede cambiar por bots o metadatos y **no renueva leases por sí mismo**.
`FactoryAgentActivity::build()` exige siete repositorios en orden canónico, identidad exacta, timestamps válidos, estado de frescura y listas paginadas completas. `issues=null` o `pull_requests=null` produce `UNKNOWN` en el contador afectado. La última señal solo se muestra cuando las tres clases de fuente están completas y `freshness=current`; datos obsoletos no promueven actividad.
`planned_unlockable` **no equivale** a blocked, unmaterialized o cualquier planned: requiere clasificación confiable de dependencias y gates. Sin esa evidencia se pasa `null`; la alerta «cola vacía» solo se emite si hay cero disponibles verificados en los siete repositorios y al menos un planificado desbloqueable confirmado. La ausencia de evidencia significa `UNKNOWN`, nunca cero.

La vista es HTML semántico de solo lectura, con fuente, timestamp, antigüedad y frescura. No muestra cuerpos de Issues, usuarios, emails, PII, cookies o secretos. No introduce endpoints públicos, escritura de GitHub ni credenciales nuevas. El endpoint actual permanece protegido con la autorización owner.

Prueba local: `python3 -m unittest discover -s tests -p test_factory_agent_activity.py`.
El PR requiere el check agregado `Validar` terminal en el HEAD exacto, independientemente de que los tests verifiquen su contrato YAML.
