# Deploy de HexBadge en cPanel — securehex.cl

HexBadge es **PHP puro** (sin Composer, sin frameworks, sin binarios). Son **dos
frontends** que comparten el mismo código y la misma base de datos:

| Subdominio | App | Document Root | Qué es |
|---|---|---|---|
| `badge.securehex.cl`  | admin   | `hexbadge/apps/admin/public`  | Panel interno: login, gestión, emisión, API |
| `earner.securehex.cl` | público | `hexbadge/apps/earner/public` | Verificación, imágenes, wallet, landing |

Todo el resto del proyecto (`src/`, `config/`, `database/`, `storage/`, `.env`)
queda **fuera** de los document roots → inaccesible desde la web.

---

## 1. Preparar y subir los archivos

1. Comprimí el proyecto en un `.zip`. **Excluí** lo que es solo de desarrollo:
   `docker/`, `docker-compose.yml`, `.git/`, `_shots/`, `*.md` opcionales.
2. En cPanel → **File Manager**, subí el zip a `/home/USUARIO/` (la carpeta home,
   **NO** dentro de `public_html`) y extraélo en `/home/USUARIO/hexbadge/`.

```
/home/USUARIO/
├── public_html/                 ← no se usa para la app
└── hexbadge/
    ├── apps/admin/public/        ← docroot de badge.securehex.cl
    ├── apps/earner/public/       ← docroot de earner.securehex.cl
    ├── src/ config/ database/ storage/   ← privados
    └── .env                      ← lo crea el instalador
```

## 2. Base de datos (cPanel → MySQL® Databases)

1. Creá una base → queda `USUARIO_hexbadge`.
2. Creá un usuario MySQL con contraseña fuerte → `USUARIO_hexbadge`.
3. Agregá el usuario a la base con **ALL PRIVILEGES**.
4. Anotá: **host `localhost`**, nombre, usuario y contraseña (con prefijo `USUARIO_`).

## 3. Crear los dos subdominios (cPanel → Domains / Subdomains)

Creá cada subdominio y **fijá su Document Root** (cPanel sugiere uno por defecto,
sobreescribilo):

| Subdominio | Document Root |
|---|---|
| `badge`  (badge.securehex.cl)  | `hexbadge/apps/admin/public` |
| `earner` (earner.securehex.cl) | `hexbadge/apps/earner/public` |

> Si tu DNS lo maneja un tercero (Cloudflare, etc.), creá los registros **A** de
> `badge` y `earner` apuntando a la IP del servidor.

## 4. PHP 8.3 (cPanel → MultiPHP Manager)

Seleccioná **PHP 8.3** para `badge.securehex.cl` y `earner.securehex.cl`.
En "Select PHP Version" verificá que estén activas: `pdo_mysql`, `gd`, `fileinfo`,
`openssl` (vienen activas por defecto).

## 5. SSL / HTTPS (cPanel → SSL/TLS Status o AutoSSL)

Emití certificado para **ambos** subdominios. Es obligatorio: la app fuerza
cookies `Secure` y HSTS en producción. Esperá a que ambos queden en verde.

## 6. Permisos de escritura

PHP corre como tu usuario, así que normalmente ya puede escribir. Si algo falla,
poné `0755` (carpetas) desde File Manager a:

```
hexbadge/storage  (y logs, temp, mail)
hexbadge/apps/earner/public/uploads  (y uploads/badges)
```
El instalador también escribe `hexbadge/.env` (en la raíz del proyecto) — asegurate
de que esa carpeta sea escribible durante la instalación.

## 7. Ejecutar el instalador

Abrí **https://badge.securehex.cl** → te redirige a `/install`. Completá:

| Campo | Valor |
|---|---|
| URL del panel de administración | `https://badge.securehex.cl` |
| URL pública (verificación + receptores) | `https://earner.securehex.cl` |
| DB host | `localhost` |
| DB nombre / usuario / contraseña | los del paso 2 (con prefijo `USUARIO_`) |
| Administrador | tu nombre, email y contraseña (mín. 12 caracteres) |

Al guardar: crea el `.env`, ejecuta el schema, crea tu superadmin, se autobloquea
y te lleva al login. **Una sola instalación configura las dos apps** (comparten
`.env`).

## 8. SMTP (panel → Configuración / SMTP)

Cargá los datos de tu casilla (host, puerto, usuario, contraseña). Para que los
correos **lleguen** (y no caigan en spam), configurá **SPF y DKIM** del dominio
remitente: cPanel → **Email Deliverability** → Repair.

## 9. Verificación final

- [ ] `https://badge.securehex.cl` → login del panel ✅
- [ ] `https://earner.securehex.cl` → landing del portal ✅
- [ ] Crear un template (subir imagen) → la imagen se ve (servida desde earner) ✅
- [ ] Emitir un badge de prueba a tu propio email → llega el correo ✅
- [ ] Abrir el enlace de aceptación → registrar/loguear → aceptar ✅
- [ ] `https://earner.securehex.cl/verify/{uuid}` muestra la verificación ✅
- [ ] Probar "Agregar a LinkedIn" desde la verificación ✅

## Notas

- **No subas** un `.env` desde tu máquina: lo genera el instalador en el servidor.
- `scripts/install.php` (instalador por consola) **no se usa** en cPanel.
- Si necesitás procesar CSV grandes (>100 filas), programá un **cron job**:
  `php /home/USUARIO/hexbadge/scripts/bulk_process.php`
- Backups: respaldá la base de datos y la carpeta `hexbadge/` periódicamente.
