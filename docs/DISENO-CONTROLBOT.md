# ControlBot — sistema visual del centro de operación

## Identidad y alcance
Centro privado del dueño con grafito, señal verde azulada y tipografía de sistema; sin imágenes, fuentes ni JavaScript externos. No cambia rutas, contratos API, autenticación, estados HEALTH ni negocio. El shell expone secciones sin ruta como elementos deshabilitados; la sección activa tiene atributo aria-current, subrayado y barra lateral, no solo color.

## Dos temas automáticos
UiTheme::tokensCss define :root claro y @media(prefers-color-scheme:dark) oscuro. Conserva bg, panel, text, muted, cyan, green, amber y red; añade body-soft, field-bg, control-hover, accent-surface, on-accent, on-green, warning-bg, warning-border, warning-text, info-border y cyan-soft.
| Token | Claro | Oscuro |
| --- | --- | --- |
| bg / panel | #f1f7f5 / #ffffff | #0c1419 / #122129 |
| panel-raised / text | #e7f1ed / #16282c | #193039 / #edf7f6 |
| muted / cyan | #4c6765 / #0d5f59 | #a7bcc4 / #71ded5 |
| green / amber / red | #176444 / #825008 / #a52d3e | #8ee6b5 / #f4c879 / #ff9c9f |

Contraste WCAG AA >= 4.5:1: texto/señales en bg, panel, panel-raised y field-bg; también on-accent/cyan, on-green/green y warning-text/warning-bg. Sin selector manual ni almacenamiento de preferencia.

## Navegación, accesibilidad y reversión
Desde 860px: carril sticky de 244px y navegación vertical. En móvil: cabecera y barra horizontal desplazable, enlaces táctiles >=44px. Foco visible, rutas protegidas y escape HTML conservados. prefers-reduced-motion:reduce elimina transiciones, animaciones y scroll suave.
DecisionUi y OwnerLoginGate sustituyen colores CSS fijos por tokens; el login conserva CSRF, throttling, sesión y CSP. Tests: test_control_theme_redesign.py y regresiones visuales existentes. Revertir el squash restaura la apariencia; sin migración, despliegue, go-live, permisos ni cambio de versión.
