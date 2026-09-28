# ControlBot Public

Directorio web público para ControlBot. 

## Configuración en Hostinger

Para que ControlBot funcione correctamente en Hostinger:

1. **Web Root**: Configurar el web root (document root) de tu dominio/subdominio a este directorio `public/`
   - En cPanel/Hostinger: Addon Domains → Document Root → apunta a `public/`

2. **Permisos**: Asegurar que PHP está habilitado
   - Los archivos `.php` deben ser ejecutables
   - Los permisos deben permitir lectura y ejecución (755 típicamente)

3. **mod_rewrite**: Debe estar habilitado para que funcionen las rewrite rules del `.htaccess`
   - Solicitarlo a Hostinger si da error 403 o 500

## Archivos

- `index.php` - Página de inicio de ControlBot
- `.htaccess` - Rewrite rules para enrutamiento

## Troubleshooting

**Error 403 (Forbidden)**
- Verificar que el web root apunta a `public/`
- Verificar permisos de directorios (755) y archivos (644)
- Verificar que mod_rewrite está habilitado

**Error 500 (Internal Server Error)**
- Revisar error logs de Hostinger/Apache
- Verificar que PHP 8.5+ está disponible
- Verificar sintaxis de `.htaccess`

## Links útiles

- [Factory](https://github.com/pl0n3r/Factory)
- [ControlBot Repo](https://github.com/pl0n3r/ControlBot)
