# WP Monitor Agent

WP Monitor Agent es un plugin WordPress liviano y de solo lectura que expone un endpoint REST privado para consultar el estado tecnico de un sitio desde n8n u otra herramienta externa.

## Instalacion manual

1. Subir la carpeta wp-monitor-agent a wp-content/plugins/.
2. Activar el plugin desde el panel de WordPress.

## Autenticacion

1. Crear un usuario administrador dedicado o usar uno existente.
2. Generar una Application Password desde el perfil del usuario.
3. Configurar Basic Auth en n8n con usuario y Application Password.

El endpoint requiere autenticacion valida y capacidad manage_options.

## Endpoint REST

Endpoint principal:

GET https://dominio.com/wp-json/wp-monitor-agent/v1/status

Ejemplo con detalle basico:

GET https://dominio.com/wp-json/wp-monitor-agent/v1/status?detail=basic

Ejemplo forzando refresh de updates:

GET https://dominio.com/wp-json/wp-monitor-agent/v1/status?refresh_updates=1

Parametros disponibles:

- detail=basic|full
- refresh_updates=1

## Configuracion del actualizador GitHub

El plugin ya incluye estos valores por defecto segun el repositorio actual:

```php
define( 'WP_MONITOR_AGENT_GITHUB_OWNER', 'marcodigitalDev' );
define( 'WP_MONITOR_AGENT_GITHUB_REPO', 'WP-Monitor-Agent' );
define( 'WP_MONITOR_AGENT_GITHUB_BRANCH', 'main' );
define( 'WP_MONITOR_AGENT_GITHUB_TOKEN', '' );
```

No necesitas definir nada extra en un sitio normal mientras el plugin siga publicandose desde ese mismo repositorio publico.

Si necesitas sobreescribir esos valores en una instalacion concreta, puedes definirlos en wp-config.php antes de cargar WordPress:

```php
define( 'WP_MONITOR_AGENT_GITHUB_OWNER', 'marcodigitalDev' );
define( 'WP_MONITOR_AGENT_GITHUB_REPO', 'WP-Monitor-Agent' );
define( 'WP_MONITOR_AGENT_GITHUB_BRANCH', 'main' );
define( 'WP_MONITOR_AGENT_GITHUB_TOKEN', '' );
```

El token es opcional para repositorios publicos. Para repositorios privados puede ser necesario para consultar metadata y descargar el paquete. La constante de branch queda disponible como configuracion futura, aunque el updater actual consulta GitHub Releases y no una rama concreta.

## Publicar una nueva version

1. Actualizar la version en el header del plugin.
2. Actualizar la constante WP_MONITOR_AGENT_VERSION.
3. Crear commit.
4. Crear tag:

```bash
git tag v1.0.1
git push origin v1.0.1
```

5. Crear un GitHub Release con el tag correspondiente.
6. Adjuntar un asset ZIP llamado wp-monitor-agent.zip.
7. Si no existe el asset ZIP, el updater usara zipball_url como fallback.
8. WordPress detectara la actualizacion desde el panel de Plugins.

## Seguridad

- El endpoint requiere autenticacion.
- El usuario debe tener manage_options.
- El plugin no expone credenciales ni variables de entorno.
- El plugin no expone logs completos.
- El plugin no modifica el sitio.
- Se recomienda restringir acceso por IP desde Cloudflare o WAF si aplica.

## Ejemplo de respuesta reducida

```json
{
  "success": true,
  "plugin": {
    "name": "WP Monitor Agent",
    "version": "1.0.0",
    "slug": "wp-monitor-agent",
    "update_source": "github"
  },
  "site": {
    "name": "Example Site",
    "site_url": "https://example.com",
    "home_url": "https://example.com/",
    "admin_url": "https://example.com/wp-admin/",
    "rest_url": "https://example.com/wp-json/",
    "environment_type": "production",
    "multisite": false
  },
  "core": {
    "installed_version": "6.8",
    "latest_version": "6.8.1",
    "update_available": true,
    "update_type": "minor"
  },
  "plugins": {
    "total_installed": 18,
    "total_active": 14,
    "updates_available": 2
  },
  "warnings": [],
  "generated_at": "2026-04-28T12:00:00+00:00",
  "timezone": "Europe/Madrid"
}
```

## Notas operativas

- n8n debe encargarse del historico, comparacion, alertas y reportes.
- Este plugin solo expone diagnostico puntual y actualizacion nativa desde GitHub.
- Para validar cache de pagina, conviene revisar headers externos como cf-cache-status, x-litespeed-cache, x-cache y cache-control desde n8n.