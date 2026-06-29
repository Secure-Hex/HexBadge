# Guía de contribución

¡Gracias por tu interés en HexBadge! Esta guía resume cómo levantar el entorno, las **restricciones técnicas** del proyecto y el estilo esperado para los Pull Requests.

## ⚠️ Filosofía y restricciones (leer primero)

HexBadge está pensado para correr en **hosting compartido / cPanel** sin privilegios especiales. Por eso, toda contribución **debe** respetar:

- **PHP 8.3 puro.** Sin frameworks (Laravel, Symfony, etc.).
- **Sin Composer / sin `vendor/`.** Si necesitás una librería, vendorizá un único archivo PHP autocontenido (como se hizo con FPDF/FreeType) y justificá por qué.
- **Sin binarios externos ni `exec`/`shell_exec`/`proc_open`.** Todo en PHP (los QR, TOTP, PDF y certificados ya se generan en PHP puro). Los únicos `exec` permitidos son `PDO::exec`.
- **Compatible con cPanel:** nada que requiera SSH, cron obligatorio, ni extensiones raras. Extensiones asumidas: `pdo_mysql`, `gd` (con FreeType), `fileinfo`, `openssl`.
- **Seguridad por defecto:** escapá toda salida, validá toda entrada, respetá CSRF, rate limiting y el **aislamiento por empresa** (multitenancy).

Si tu cambio rompe alguno de estos puntos, probablemente no se pueda aceptar tal cual — abrí un issue primero para conversarlo.

## 🛠️ Entorno de desarrollo

Requiere Docker + Docker Compose.

```bash
git clone <tu-fork> hexbadge && cd hexbadge
docker compose up -d --build
```

| Servicio | URL |
|---|---|
| Panel admin | http://localhost:8088 |
| Portal público | http://localhost:8089 |
| Mailpit (correos de prueba) | http://localhost:8025 |
| MySQL | `localhost:3306` |

Abrí http://localhost:8088 y completá el instalador (DB host `db`, base `hexbadge`, usuario `hexbadge_user`, contraseña `hexbadge_dev_pass` — definidos en `docker-compose.yml`, **solo para desarrollo**).

## 🧭 Arquitectura y convenciones

- **Dos apps** que comparten código y base de datos:
  - `apps/admin/public` → panel interno (`src/Admin/`).
  - `apps/earner/public` → portal público / verificación (`src/Earner/`).
  - Código compartido en `src/Core`, `src/Models`, `src/Services`.
- **Autoloader PSR-4** propio: `HexBadge\` → `src/`. Nada de `require` manual de clases.
- **Helpers globales** (en `src/bootstrap.php`): `e()` (escape HTML), `config('namespace.clave')` (un archivo por namespace en `config/`), `public_url()`, `badge_image_url()`, `uuid4()`.
- `declare(strict_types=1);` en **todos** los archivos PHP. Tipos en firmas y retornos.
- **Vistas:** PHP plano; **siempre** escapá con `e()`. JS solo en archivos locales servidos por la app (la CSP es `script-src 'self'` → nada de inline handlers ni CDNs).
- **Estilos:** `app.css` y las fuentes están **duplicados** en `apps/admin/public/assets` y `apps/earner/public/assets`. Si editás uno, **copialo al otro**.
- **Controladores** extienden `HexBadge\Core\Controller`. Para acceso usá `Auth::requireRole(...)`; para multitenancy usá los helpers `companyFilter()`, `companyForWrite()`, `assertCompanyAccess()` y `companiesForSelector()`.
- **Multitenancy:** cualquier método de modelo que liste/cuente debe aceptar `?int $companyId` (null = sin filtro = solo superadmin). Toda vista de detalle/edición debe validar pertenencia con `assertCompanyAccess()`.

## 🗄️ Cambios en la base de datos

- Agregá una migración incremental en `database/migrations/0XX_descripcion.sql`.
- Actualizá `database/schema.sql` (instalaciones nuevas).
- Reflejá el cambio en `MIGRATION-PROD.sql`, que **debe seguir siendo idempotente** (usa guardas con `information_schema` + `PREPARE/EXECUTE`, sin `DELIMITER`, para que se pueda correr varias veces en phpMyAdmin sin romper).

## ✅ Probar tu cambio

No hay framework de tests todavía; la verificación es manual + lint:

```bash
# Lint de sintaxis de todo lo tocado
docker exec hexbadge_admin sh -c 'cd /var/www/html && find src -name "*.php" -exec php -l {} \;' | grep -v "No syntax errors"
```

- Probá el flujo afectado en el panel (http://localhost:8088) y revisá los correos en **Mailpit**.
- Si tocás **multitenancy**, verificá el aislamiento: un `admin`/`issuer` **no** debe ver datos de otra empresa (probá con dos cuentas en ventanas separadas).
- Si tocás **certificados**, verificá el PDF generado y que el QR decodifica a la URL de verificación.

## 📤 Pull Requests

- Trabajá en una **rama** por cambio; mantené el PR **enfocado** (un tema por PR).
- Describí **qué** cambia y **por qué**. Si toca la base de datos, indicá la migración.
- Si tu cambio afecta la UI, incluí una captura.
- Listá los **archivos modificados** (ayuda a quienes despliegan subiendo solo lo cambiado a cPanel).
- Mantené el estilo del código existente; no reformatees archivos enteros sin necesidad.
- Asegurate de no commitear secretos: `.env`, credenciales ni datos reales.

## 🐛 Reportar bugs y vulnerabilidades

- **Bugs:** abrí un issue con pasos para reproducir, comportamiento esperado vs. real y tu entorno.
- **Vulnerabilidades de seguridad:** **no** uses issues públicos — seguí la [política de seguridad](SECURITY.md).

¡Gracias por aportar! 🙌
