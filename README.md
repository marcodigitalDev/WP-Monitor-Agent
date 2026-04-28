# WP Monitor Agent

WP Monitor Agent es un plugin WordPress liviano y de solo lectura que expone un endpoint REST privado para consultar el estado tecnico de un sitio desde n8n u otra herramienta externa.

## Instalacion manual

1. Subir la carpeta wp-monitor-agent a wp-content/plugins/.
2. Activar el plugin desde el panel de WordPress.

## Autenticacion

Flujo recomendado (mas simple para n8n):

1. Activar el plugin.
2. Obtener el token del plugin desde la opcion wp_monitor_agent_api_token (se genera automaticamente en la activacion) o definir tu propio token en wp-config.php con WP_MONITOR_AGENT_API_TOKEN.
3. En n8n, enviar ese token en el header Authorization: Bearer TU_TOKEN (o X-WP-Monitor-Token: TU_TOKEN).

Tambien se mantiene compatibilidad con usuarios administradores autenticados (capacidad manage_options).

## Endpoint REST

Endpoint principal:

GET <https://dominio.com/wp-json/wp-monitor-agent/v1/status>

Ejemplo con detalle basico:

GET <https://dominio.com/wp-json/wp-monitor-agent/v1/status?detail=basic>

Ejemplo forzando refresh de updates:

GET <https://dominio.com/wp-json/wp-monitor-agent/v1/status?refresh_updates=1>

Parametros disponibles:

- detail=basic|full
- refresh_updates=1

Headers de autenticacion soportados:

- Authorization: Bearer TU_TOKEN
- X-WP-Monitor-Token: TU_TOKEN

## Ejemplo de respuesta reducida

```json
{
  "success": true,
  "plugin": {
    "name": "WP Monitor Agent",
    "version": "1.0.3",
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
