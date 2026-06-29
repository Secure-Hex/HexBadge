# Política de seguridad

HexBadge maneja credenciales, datos personales de receptores y configuración sensible (SMTP, secretos). Nos tomamos la seguridad en serio y agradecemos los reportes responsables.

## Versiones soportadas

Se da soporte de seguridad a la **última versión** publicada en la rama principal. Si mantenés un fork o un despliegue propio, mantenelo al día con los últimos parches.

| Versión | Soporte |
|---|---|
| `main` (última) | ✅ |
| Versiones anteriores | ❌ (actualizá) |

## Reportar una vulnerabilidad

**No abras un issue público ni un Pull Request** para reportar una vulnerabilidad: eso la expondría antes de que exista un parche.

En su lugar, reportala de forma privada:

- 📧 **contacto@securehex.cl**
- Si está disponible, usá **GitHub → Security → Report a vulnerability** (Private Vulnerability Reporting).

Incluí, en lo posible:

1. Descripción del problema y su **impacto** (qué se puede lograr).
2. **Pasos para reproducir** (o una prueba de concepto).
3. Componente/archivo afectado y versión/commit.
4. Cualquier mitigación temporal que conozcas.

### Qué esperar

- **Acuse de recibo:** dentro de **72 horas hábiles**.
- **Evaluación inicial y severidad:** dentro de **7 días**.
- **Corrección:** según severidad; priorizamos las críticas/altas.
- Te mantenemos al tanto del avance y coordinamos la fecha de divulgación.

### Divulgación coordinada

Pedimos **divulgación coordinada**: dennos un plazo razonable para publicar el parche antes de hacer público el detalle. Con gusto te damos crédito en el changelog/avisos (salvo que prefieras el anonimato).

## Alcance

Aplica al código de este repositorio (panel admin, portal público, API, generación de certificados, etc.).

**Especialmente relevante:** autenticación y sesiones, control de acceso y **aislamiento entre empresas (multitenancy)**, CSRF, subida de archivos (imágenes/plantillas/fuentes), generación de certificados, API con API keys, y manejo de secretos (SMTP).

**Fuera de alcance:** vulnerabilidades de dependencias de infraestructura que no controla la app (servidor web, MySQL, PHP del hosting), ataques que requieren acceso físico o credenciales ya comprometidas, y configuraciones inseguras del operador (ej. no usar HTTPS).

## Medidas ya implementadas

HexBadge incorpora defensa en profundidad:

- Contraseñas con **bcrypt (cost 12)**; **2FA/TOTP** opcional (RFC 6238).
- **CSRF** en todos los formularios; sesiones con cookies `HttpOnly`/`Secure` y regeneración de ID al loguear.
- **Rate limiting** por IP (login, verificación, API, subida de CSV).
- **Cifrado AES-256-GCM** para secretos en BD (contraseñas SMTP).
- **CSP** y headers de seguridad (`X-Frame-Options`, `nosniff`, HSTS en producción).
- **Aislamiento por empresa**: cada admin/issuer solo ve y opera sobre su tenant.
- Validación y saneo de subidas (tipo MIME + magic bytes; SVG sanitizado).
- Sin `exec`/shell ni dependencias externas (menor superficie de ataque).

## Recomendaciones de despliegue seguro

Para un despliegue endurecido (ver también [INSTALL-CPANEL.md](INSTALL-CPANEL.md)):

- Serví **siempre por HTTPS**; en producción la app fuerza cookies `Secure` y HSTS.
- Mantené `src/`, `config/`, `database/`, `storage/` y `.env` **fuera** de los document roots.
- `.env` con permisos restrictivos; **nunca** lo subas al repositorio.
- Generá un `APP_SECRET` único y fuerte (`php -r "echo bin2hex(random_bytes(32));"`).
- Permisos de archivos subidos en `0644` y directorios en `0755` (lo mínimo necesario).
- Configurá **SPF + DKIM** del dominio remitente.
- Cambiá toda credencial por defecto (las del `docker-compose.yml` son **solo para desarrollo**).
- Hacé **backups** periódicos de la base de datos.
