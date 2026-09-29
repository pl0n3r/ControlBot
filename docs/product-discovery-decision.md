# Product Discovery Decision v1

`ProductDiscoveryDecision` separa una clasificación de evidencia de una decisión de producto. Recalcula el Assessment desde Initiative, Hypothesis, Experiment Plan y Outcome; no acepta un assessment normalizado por el caller como autoridad.

La decisión es explícita y cerrada a `BUILD | ITERATE | PARK | STOP | RESEARCH_MORE`. `BUILD` solo es válido sobre `VALIDATED`, pero `VALIDATED` nunca selecciona BUILD automáticamente: una decisión distinta sigue siendo válida.

Todo BUILD conserva pendientes `authority`, `budget`, `aegis` y `lex`, fija `factory_handoff_ready=false` y `execution=false`. El schema no acepta flags o refs caller-supplied que pretendan autocertificar esos gates. Las decisiones no BUILD no crean gates ni trabajo ejecutable.

La salida preserva scope Venture/Product, classification, assessment rule/evidence, evaluation window, freshness, confidence y provenance sin elevar evidencia. Refs y listas son cerradas, deterministas y libres de material sensible.

Este slice no materializa WorkItems, no evalúa authority/budget/AEGIS/LEX, no persiste estado y no ejecuta providers.
