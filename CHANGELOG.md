# Changelog

## [1.2.0] - 2026-07-28
### Nuevas funcionalidades
- la credencial se presenta como pieza catalogada (afbbcf9)
- la pantalla de decisión explica qué implica aceptar (5b4d762)
- jerarquía profesional en el perfil y la vitrina de credenciales (97bd742)
- el veredicto encabeza la página pública (107637e)
- revisar el lote antes de emitirlo (42b4455)
### Correcciones
- las tarjetas de credencial se solapaban entre sí (bfc6dcd)
- /buscar devolvía un fragmento sin layout (6f2fc12)
- páginas de error, analytics honesto y consistencia de idioma (fc69136)
- navegación, landmarks y propósito de los campos (a355ba9)
- restaurar las confirmaciones de acciones destructivas (d225d77)
### Otros
- estilos de las piezas nuevas y limpieza del shell muerto (96fdf39)

## [1.1.1] - 2026-07-27
### Correcciones
- regenerar el PDF cuando el earner cambia su nombre (b008c2a)
### Otros
- versión de la app desde archivo VERSION (fuente única en runtime) (c89c555)

## [1.1.0] - 2026-07-10
### Nuevas funcionalidades
- exigir contraseña + 2FA de la cuenta origen al fusionar (785560c)
- avisos a ambos correos, deshacer y soft-merge visible (0769c0e)
- fusión de wallets con verificación por email (6f63996)
- base multi-correo del receptor (8e22b3f)
- auto-guardado de fotos + truncado de nombre largo (3797110)
- botón de eliminar foto de perfil/portada (borrado inmediato) (8597fd4)
- botones de subida de foto estilizados (dropzone) (4b13d30)
- rediseño del perfil del earner en tarjetas (54ba72b)
- botón "Acerca de" con la marca SecureHex en ambos portales (1d39f3a)
- handler global de errores + cache-busting de assets (6df7f02)
- previsualización instantánea de foto de perfil y portada (486bd40)
- convertir imágenes subidas a WebP (048df59)
- más opciones de compartir en la página de verificación (4bca9a8)
- diploma templates, multi-company y formatos de fecha configurables (dbe9526)
- add earner profiles and password reset (534f605)
### Correcciones
- control de acceso por empresa en jobs de emisión masiva (ad438fe)
- sanitizador SVG por DOM y neutralización de CSV injection (38fd94d)
### Documentación
- update bulk flow and profile screenshots (68dfe91)
### Otros
- versionado automático en push a main (5eaa293)
