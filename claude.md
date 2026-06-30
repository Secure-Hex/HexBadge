# CLAUDE.md — HexBadge
**Plataforma self-hosted de credenciales digitales para SecureHex**
Stack: PHP 8.3 puro · MySQL 8.0 · Open Badges 2.0

---

## 1. VISIÓN DEL PROYECTO

HexBadge es una plataforma **self-hosted** de emisión y gestión de badges digitales verificables, equivalente funcional a Credly/Acreditta, construida íntegramente en PHP puro sin frameworks. Pertenece al ecosistema de herramientas Hex de SecureHex.

**Propósito principal:** Permitir a SecureHex emitir badges verificables a participantes de trainings, clientes, eventos y certificaciones propias, con control total sobre los datos, la marca y la infraestructura.

**Principios de diseño:**
- Seguridad primero — toda decisión de arquitectura prioriza la seguridad sobre la conveniencia
- Sin frameworks — PHP puro, sin Laravel/Symfony, sin Composer salvo librerías de seguridad críticas
- Open Badges 2.0 — estándar IMS Global para interoperabilidad y verificación externa
- Self-hosted — corre en cualquier VPS con PHP 8.3 + MySQL 8.0 + Apache/Nginx

---

## 2. ARQUITECTURA GENERAL

```
hexbadge/
├── public/                    # Document root del servidor web
│   ├── index.php              # Front controller + router
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── img/
│   ├── uploads/               # Imágenes de badges (permisos: 750)
│   └── verify/
│       └── index.php          # Portal público de verificación (no requiere login)
├── src/
│   ├── Core/
│   │   ├── Router.php         # Router HTTP minimalista
│   │   ├── Request.php        # Abstracción de $_REQUEST/$_SERVER
│   │   ├── Response.php       # Helpers de respuesta HTTP
│   │   ├── Database.php       # Singleton PDO con prepared statements
│   │   ├── Session.php        # Gestión segura de sesiones
│   │   ├── Auth.php           # Autenticación + autorización RBAC
│   │   ├── CSRF.php           # Tokens CSRF
│   │   ├── RateLimiter.php    # Rate limiting por IP/usuario
│   │   ├── Validator.php      # Validación y sanitización de inputs
│   │   └── Logger.php         # Log de auditoría (no expone datos sensibles)
│   ├── Models/
│   │   ├── User.php
│   │   ├── BadgeTemplate.php
│   │   ├── IssuedBadge.php
│   │   ├── Earner.php
│   │   └── AuditLog.php
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── BadgeTemplateController.php
│   │   ├── IssueController.php
│   │   ├── BulkIssueController.php
│   │   ├── VerifyController.php
│   │   ├── EarnerController.php
│   │   ├── AnalyticsController.php
│   │   └── ApiController.php
│   ├── Services/
│   │   ├── BadgeService.php         # Lógica de negocio de badges
│   │   ├── OpenBadgeService.php     # Generación de assertion OB 2.0
│   │   ├── EmailService.php         # Notificaciones SMTP
│   │   ├── CsvImportService.php     # Procesamiento de CSV masivo
│   │   ├── ImageService.php         # Procesamiento seguro de imágenes
│   │   └── ApiKeyService.php        # Gestión de API keys
│   └── Views/
│       ├── layout/
│       │   ├── header.php
│       │   ├── footer.php
│       │   └── nav.php
│       ├── auth/
│       ├── dashboard/
│       ├── badges/
│       ├── issue/
│       ├── earner/
│       ├── verify/
│       └── analytics/
├── config/
│   ├── config.php             # Configuración general (cargada desde .env)
│   ├── database.php           # Config de base de datos
│   └── mail.php               # Config SMTP
├── database/
│   ├── schema.sql             # DDL completo
│   └── migrations/            # Migraciones numeradas: 001_initial.sql, etc.
├── scripts/
│   └── install.php            # Script de instalación inicial
├── storage/
│   ├── logs/                  # Logs de aplicación (fuera del document root)
│   └── temp/                  # Archivos temporales de CSV (se eliminan tras procesamiento)
├── .env.example               # Variables de entorno (nunca commitear .env)
├── .htaccess                  # Reglas Apache (o nginx.conf para Nginx)
└── CLAUDE.md                  # Este archivo
```

**Separación crítica:** Todo lo que esté en `src/`, `config/`, `database/`, `storage/` debe estar **fuera del document root** o protegido con `.htaccess`. Solo `public/` es accesible desde el navegador.

---

## 3. BASE DE DATOS

### 3.1 Esquema completo (`database/schema.sql`)

```sql
-- ============================================================
-- HexBadge Schema v1.0
-- MySQL 8.0 — UTF8MB4 — InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Usuarios administradores del sistema
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,            -- UUID v4 público
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,               -- password_hash(BCRYPT, cost=12)
    role          ENUM('superadmin','admin','issuer') NOT NULL DEFAULT 'issuer',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    totp_secret   VARCHAR(64) NULL,                    -- MFA TOTP (opcional)
    totp_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Keys para integración programática
CREATE TABLE api_keys (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    key_hash      VARCHAR(255) NOT NULL UNIQUE,        -- SHA-256 hash de la key real
    key_prefix    VARCHAR(12) NOT NULL,                -- Primeros chars para identificación
    name          VARCHAR(100) NOT NULL,               -- Nombre descriptivo
    scopes        JSON NOT NULL,                       -- ["badges:read","badges:issue","bulk:issue"]
    last_used_at  DATETIME NULL,
    expires_at    DATETIME NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Templates de badges (lo que se diseña y publica)
CREATE TABLE badge_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    created_by      INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT NOT NULL,
    criteria_text   TEXT NOT NULL,                     -- Texto de criterios de obtención
    criteria_url    VARCHAR(500) NULL,                 -- URL opcional de criterios
    image_filename  VARCHAR(255) NOT NULL,             -- Nombre del archivo en uploads/
    image_url       VARCHAR(500) NULL,                 -- URL pública de la imagen
    skills_tags     JSON NULL,                         -- ["pentesting","OWASP","web security"]
    issuer_name     VARCHAR(200) NOT NULL DEFAULT 'SecureHex',
    issuer_url      VARCHAR(500) NOT NULL DEFAULT 'https://securehex.cl',
    issuer_email    VARCHAR(255) NOT NULL,
    expires_days    INT UNSIGNED NULL,                 -- NULL = no expira
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_public       TINYINT(1) NOT NULL DEFAULT 1,
    badges_issued   INT UNSIGNED NOT NULL DEFAULT 0,   -- Contador desnormalizado
    state           ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receptores de badges (pueden o no ser usuarios del sistema)
CREATE TABLE earners (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    display_name  VARCHAR(200) GENERATED ALWAYS AS (CONCAT(first_name, ' ', last_name)) STORED,
    profile_bio   TEXT NULL,
    profile_url   VARCHAR(500) NULL,
    token_hash    VARCHAR(255) NULL UNIQUE,            -- Hash del token para acceso a wallet
    token_expires DATETIME NULL,
    is_verified   TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Badges emitidos (assertion Open Badges 2.0)
CREATE TABLE issued_badges (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL UNIQUE,      -- ID público de la assertion
    badge_template_id   INT UNSIGNED NOT NULL,
    earner_id           INT UNSIGNED NOT NULL,
    issued_by           INT UNSIGNED NOT NULL,         -- FK a users
    issued_via          ENUM('manual','csv','api') NOT NULL DEFAULT 'manual',
    issued_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NULL,
    status              ENUM('pending','accepted','rejected','revoked') NOT NULL DEFAULT 'pending',
    revoked_at          DATETIME NULL,
    revoke_reason       VARCHAR(500) NULL,
    notification_sent   TINYINT(1) NOT NULL DEFAULT 0,
    notification_sent_at DATETIME NULL,
    accept_token        VARCHAR(255) NULL UNIQUE,      -- Token único para aceptar el badge
    accept_token_expires DATETIME NULL,
    accepted_at         DATETIME NULL,
    ob_assertion_json   JSON NULL,                     -- Open Badge assertion completa cacheada
    locale              VARCHAR(10) NOT NULL DEFAULT 'es',
    FOREIGN KEY (badge_template_id) REFERENCES badge_templates(id),
    FOREIGN KEY (earner_id) REFERENCES earners(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    INDEX idx_earner (earner_id),
    INDEX idx_template (badge_template_id),
    INDEX idx_status (status),
    INDEX idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jobs de emisión masiva CSV
CREATE TABLE bulk_import_jobs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    user_id       INT UNSIGNED NOT NULL,
    template_id   INT UNSIGNED NOT NULL,
    filename_orig VARCHAR(255) NOT NULL,
    total_rows    INT UNSIGNED NOT NULL DEFAULT 0,
    processed     INT UNSIGNED NOT NULL DEFAULT 0,
    success_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count   INT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
    errors_json   JSON NULL,                           -- Lista de errores por fila
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (template_id) REFERENCES badge_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de auditoría inmutable
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,                     -- NULL si es acción de sistema/API
    api_key_id  INT UNSIGNED NULL,
    action      VARCHAR(100) NOT NULL,                 -- 'badge.issued', 'user.login', etc.
    entity_type VARCHAR(50) NULL,                      -- 'badge_template', 'issued_badge', etc.
    entity_id   VARCHAR(36) NULL,                      -- UUID de la entidad afectada
    ip_address  VARCHAR(45) NOT NULL,
    user_agent  VARCHAR(500) NULL,
    metadata    JSON NULL,                             -- Datos adicionales del evento
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting por IP
CREATE TABLE rate_limit_attempts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier  VARCHAR(255) NOT NULL,                 -- IP o "user:{id}"
    action      VARCHAR(100) NOT NULL,                 -- 'login', 'api', 'verify', etc.
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_action (identifier, action),
    INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. SEGURIDAD — REGLAS NO NEGOCIABLES

**Esta sección es la más importante. Claude Code debe seguirla estrictamente y sin excepciones.**

### 4.1 Autenticación y sesiones

```php
// Session.php — configuración mínima obligatoria
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'],
    'secure'   => true,           // Solo HTTPS
    'httponly' => true,           // No accesible desde JS
    'samesite' => 'Strict'        // Protección CSRF adicional
]);
session_start();
session_regenerate_id(true);      // Regenerar en cada login exitoso

// Contraseñas: SIEMPRE bcrypt con cost=12
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$ok   = password_verify($input, $hash);

// Nunca almacenar la contraseña en texto plano ni en logs
```

- Timeout de sesión: 30 minutos de inactividad → logout automático
- Un solo token de sesión activo por usuario (invalidar sesiones anteriores en nuevo login)
- CSRF token en todos los formularios POST: generado con `random_bytes(32)`, almacenado en sesión, verificado antes de procesar

### 4.2 Prevención de SQL Injection

```php
// Database.php — SIEMPRE usar prepared statements con PDO
// PROHIBIDO: interpolación directa de variables en SQL

// ✅ CORRECTO
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
$stmt->execute([$email]);

// ❌ PROHIBIDO absolutamente
$result = $pdo->query("SELECT * FROM users WHERE email = '$email'");
```

- PDO con `PDO::ATTR_EMULATE_PREPARES => false` para usar prepared statements reales
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` — nunca `PDO::ERRMODE_SILENT`
- Nunca exponer errores de base de datos al usuario; loggear internamente

### 4.3 Prevención de XSS

```php
// Regla: escapar TODO lo que se renderiza en HTML
// Usar helper global en views

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// En las views SIEMPRE:
echo e($user['name']);          // ✅
echo $user['name'];             // ❌ Prohibido sin escapar
```

- Content-Security-Policy header en todas las respuestas
- No usar `eval()`, `innerHTML` directamente, ni `document.write` en JS
- JSON de salida siempre con `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`

### 4.4 Upload seguro de imágenes (badges)

```php
// ImageService.php — validación estricta de uploads
class ImageService {
    private const ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/svg+xml'];
    private const MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2MB
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/badges/';

    public function processUpload(array $file): string {
        // 1. Verificar tamaño
        if ($file['size'] > self::MAX_SIZE_BYTES) {
            throw new \InvalidArgumentException('Imagen demasiado grande (máx. 2MB)');
        }

        // 2. Verificar MIME type real con finfo, no con $_FILES['type']
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException('Tipo de archivo no permitido');
        }

        // 3. Para SVG: sanitizar para eliminar scripts embebidos
        if ($mime === 'image/svg+xml') {
            $this->sanitizeSvg($file['tmp_name']);
        }

        // 4. Generar nombre con UUID — nunca usar el nombre original del usuario
        $ext      = $mime === 'image/png' ? 'png' : ($mime === 'image/jpeg' ? 'jpg' : 'svg');
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = self::UPLOAD_DIR . $filename;

        // 5. Mover con move_uploaded_file (seguro)
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException('Error al guardar imagen');
        }

        // 6. Permisos restrictivos
        chmod($dest, 0640);

        return $filename;
    }

    private function sanitizeSvg(string $path): void {
        $content = file_get_contents($path);
        // Eliminar tags peligrosos: script, on*, xlink:href con javascript:
        $content = preg_replace('/<script[\s\S]*?<\/script>/i', '', $content);
        $content = preg_replace('/\bon\w+\s*=/i', 'data-removed=', $content);
        $content = preg_replace('/javascript:/i', '', $content);
        file_put_contents($path, $content);
    }
}
```

- El directorio `uploads/` debe tener `.htaccess` que impida ejecución de PHP
- Nunca servir archivos subidos con `include` o `require`

### 4.5 API Keys

```php
// ApiKeyService.php
class ApiKeyService {
    // Generar key: prefijo legible + secreto seguro
    public function generate(int $userId, string $name, array $scopes): array {
        $secret  = 'hxb_' . bin2hex(random_bytes(32)); // 68 chars
        $prefix  = substr($secret, 0, 12);
        $hash    = hash('sha256', $secret);             // Guardar solo el hash

        // Guardar en DB solo prefix + hash, nunca el secret completo
        $this->db->insert('api_keys', [
            'user_id'    => $userId,
            'key_hash'   => $hash,
            'key_prefix' => $prefix,
            'name'       => $name,
            'scopes'     => json_encode($scopes),
        ]);

        return ['key' => $secret]; // Mostrar UNA SOLA VEZ al usuario
    }

    public function verify(string $rawKey): ?array {
        $hash = hash('sha256', $rawKey);
        $stmt = $this->db->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1');
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    }
}
```

- Autenticación API: header `Authorization: Bearer <key>` solamente
- Verificación con `hash_equals()` para prevenir timing attacks
- Scopes granulares: `badges:read`, `badges:issue`, `bulk:issue`, `templates:read`

### 4.6 Rate Limiting

```php
// RateLimiter.php
// Límites por defecto:
// - Login: 5 intentos / 15 minutos por IP
// - API: 100 requests / minuto por API key
// - Verify pública: 30 requests / minuto por IP
// - CSV upload: 3 uploads / hora por usuario

public function check(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool {
    $since = date('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt  = $this->db->prepare(
        'SELECT COUNT(*) FROM rate_limit_attempts 
         WHERE identifier = ? AND action = ? AND attempted_at > ?'
    );
    $stmt->execute([$identifier, $action, $since]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $maxAttempts) {
        return false; // Bloqueado
    }

    // Registrar intento
    $this->db->prepare('INSERT INTO rate_limit_attempts (identifier, action) VALUES (?, ?)')
             ->execute([$identifier, $action]);

    return true;
}
```

### 4.7 Headers HTTP de seguridad

```php
// Response.php — enviar en TODA respuesta
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
// HTTPS only (cuando esté en producción):
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
```

### 4.8 Validación de inputs

```php
// Validator.php — nunca confiar en datos del usuario
class Validator {
    public function email(string $input): string {
        $clean = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        if (!$clean) throw new \InvalidArgumentException('Email inválido');
        if (strlen($clean) > 255) throw new \InvalidArgumentException('Email demasiado largo');
        return strtolower($clean);
    }

    public function name(string $input, int $max = 100): string {
        $clean = trim(strip_tags($input));
        if (empty($clean)) throw new \InvalidArgumentException('Campo requerido');
        if (strlen($clean) > $max) throw new \InvalidArgumentException("Máximo {$max} caracteres");
        return $clean;
    }

    public function uuid(string $input): string {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $input)) {
            throw new \InvalidArgumentException('UUID inválido');
        }
        return strtolower($input);
    }
}
```

### 4.9 Logging de auditoría

- Loggear: logins (exitosos y fallidos), emisión de badges, revocaciones, cambios de usuario, accesos API
- NO loggear: contraseñas, tokens, API keys, datos personales en texto plano
- Los logs de auditoría en DB son de solo-inserción (nunca actualizar ni borrar filas de `audit_logs`)
- Los archivos de log en `storage/logs/` deben tener permisos 640

### 4.10 Otras reglas

- `error_reporting(0)` y `display_errors=0` en producción — errores solo a logs
- Nunca usar `md5()` o `sha1()` para contraseñas ni tokens de seguridad
- Variables de entorno desde `.env` — nunca hardcodear credenciales en código
- UUID v4 generado con `sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', ...)` + `random_bytes`
- Verificar `is_uploaded_file()` antes de procesar cualquier upload
- Nunca confiar en `$_SERVER['HTTP_HOST']` para URLs base — usar config

---

## 5. OPEN BADGES 2.0

HexBadge debe generar assertions compatibles con el estándar IMS Global Open Badges 2.0.

### 5.1 Estructura de una Assertion

```php
// OpenBadgeService.php — generar assertion JSON-LD
class OpenBadgeService {
    public function buildAssertion(array $issuedBadge, array $template, array $earner): array {
        $assertionUrl = config('app.url') . '/verify/' . $issuedBadge['uuid'];

        return [
            '@context'  => 'https://w3id.org/openbadges/v2',
            'type'      => 'Assertion',
            'id'        => $assertionUrl,
            'badge'     => $this->buildBadgeClass($template),
            'recipient' => [
                'type'     => 'email',
                'hashed'   => true,
                'salt'     => $issuedBadge['uuid'], // Usar UUID como salt
                'identity' => 'sha256$' . hash('sha256', $earner['email'] . $issuedBadge['uuid'])
            ],
            'issuedOn'  => date('c', strtotime($issuedBadge['issued_at'])),
            'expires'   => $issuedBadge['expires_at']
                            ? date('c', strtotime($issuedBadge['expires_at']))
                            : null,
            'verification' => [
                'type'           => 'hosted',
                'allowedOrigins' => config('app.url')
            ]
        ];
    }

    private function buildBadgeClass(array $template): array {
        return [
            'type'        => 'BadgeClass',
            'id'          => config('app.url') . '/badges/' . $template['uuid'],
            'name'        => $template['name'],
            'description' => $template['description'],
            'image'       => config('app.url') . '/uploads/badges/' . $template['image_filename'],
            'criteria'    => [
                'narrative' => $template['criteria_text'],
                'id'        => $template['criteria_url'] ?? null
            ],
            'issuer'      => [
                'type'  => 'Profile',
                'id'    => config('app.url') . '/issuer',
                'name'  => $template['issuer_name'],
                'url'   => $template['issuer_url'],
                'email' => $template['issuer_email']
            ],
            'tags'        => json_decode($template['skills_tags'] ?? '[]', true)
        ];
    }
}
```

### 5.2 Endpoint de verificación pública

`GET /verify/{uuid}` — accesible sin autenticación:
- Muestra HTML con los datos del badge (nombre, template, earner hasheado, fecha, estado)
- Incluye botón "Ver JSON" que retorna el assertion JSON-LD con `Content-Type: application/json`
- Si el badge está revocado: mostrar mensaje de revocación con razón
- Si el badge está expirado: mostrar advertencia visual

---

## 6. MÓDULOS FUNCIONALES

### 6.1 Gestión de Badge Templates

**Rutas:**
```
GET  /admin/templates          → listar templates
GET  /admin/templates/new      → formulario creación
POST /admin/templates          → crear template
GET  /admin/templates/{uuid}   → ver template
GET  /admin/templates/{uuid}/edit → formulario edición
POST /admin/templates/{uuid}   → actualizar template
POST /admin/templates/{uuid}/archive → archivar
```

**Campos del formulario:**
- Nombre del badge (requerido, max 200)
- Descripción (requerido, textarea)
- Criterios de obtención (requerido, textarea)
- URL de criterios (opcional)
- Imagen del badge (requerido en creación, PNG/JPG/SVG, max 2MB, recomendado 600×600px)
- Skills/etiquetas (campo de tags dinámico)
- Días de expiración (opcional, input numérico)
- Visibilidad (público/privado)

**Flujo:** Draft → publicar → archivar (no se puede eliminar si tiene badges emitidos)

### 6.2 Emisión Individual

**Ruta:** `POST /admin/issue`

**Formulario:**
- Seleccionar template (dropdown de templates activos)
- Email del receptor (validar formato)
- Nombre y apellido del receptor
- Fecha de emisión (default: hoy)
- Idioma de notificación (es/en)

**Flujo:**
1. Validar inputs
2. Buscar o crear `earner` por email
3. Verificar no haya badge duplicado activo (mismo template + earner)
4. Crear `issued_badge` con `status='pending'`
5. Generar `accept_token` con `bin2hex(random_bytes(32))`, expiración 30 días
6. Generar Open Badge assertion y cachear en `ob_assertion_json`
7. Enviar email de notificación con link de aceptación
8. Registrar en `audit_logs`

### 6.3 Emisión Masiva CSV

**Ruta:** `POST /admin/bulk-issue`

**Formato CSV esperado:**
```csv
badge_template_id,first_name,last_name,email,locale
{uuid},{nombre},{apellido},{email},es
```

**Flujo:**
1. Validar archivo (CSV, max 5MB, max 5.000 filas)
2. Crear `bulk_import_job` con `status='queued'`
3. Mover CSV a `storage/temp/` (fuera del document root)
4. Procesar el lote de forma síncrona en la propia request (hasta 2000 filas), incluido el envío de correos en un solo lote SMTP
5. Por cada fila: validar, crear earner si no existe, emitir badge, registrar error si falla
6. Al finalizar: actualizar job con conteos de éxito/error, generar CSV de errores descargable
7. Eliminar el CSV temporal de `storage/temp/`

**Reglas:**
- Fila con email inválido → registrar error y continuar (no abortar todo el lote)
- Duplicado (mismo template + email ya tiene badge activo) → registrar como "omitido", no error
- Las notificaciones de email se envían en lote, no en tiempo real durante el procesamiento

### 6.4 API REST

**Base URL:** `/api/v1`
**Autenticación:** `Authorization: Bearer <api_key>` en cada request
**Formato:** JSON en request y response
**Versionado:** El número de versión va en la URL (`/api/v1/`)

**Endpoints MVP:**

```
# Templates
GET  /api/v1/templates              → listar templates activos
GET  /api/v1/templates/{uuid}       → obtener template
     Scope requerido: badges:read

# Emisión
POST /api/v1/badges/issue           → emitir badge individual
     Scope: badges:issue
     Body: { template_id, email, first_name, last_name, locale? }

POST /api/v1/badges/bulk-issue      → emitir múltiples (array, max 100 por request)
     Scope: bulk:issue
     Body: { template_id, earners: [{email, first_name, last_name}] }

# Consulta
GET  /api/v1/badges/{uuid}          → estado de un badge
     Scope: badges:read

GET  /api/v1/earners/{email}/badges → badges de un earner
     Scope: badges:read

# Revocación
DELETE /api/v1/badges/{uuid}        → revocar badge
       Scope: badges:issue
       Body: { reason }
```

**Respuesta estándar:**
```json
{
  "success": true,
  "data": { ... },
  "meta": { "timestamp": "2025-01-01T00:00:00Z", "version": "1.0" }
}
```

**Error estándar:**
```json
{
  "success": false,
  "error": { "code": "INVALID_EMAIL", "message": "El email no es válido" },
  "meta": { "timestamp": "2025-01-01T00:00:00Z" }
}
```

**Códigos de error API:**
- `INVALID_TOKEN` — API key inválida o expirada (401)
- `INSUFFICIENT_SCOPE` — Scope no autorizado (403)
- `RATE_LIMITED` — Demasiadas requests (429)
- `TEMPLATE_NOT_FOUND` — Template no existe o no está activo (404)
- `DUPLICATE_BADGE` — El earner ya tiene este badge activo (409)
- `VALIDATION_ERROR` — Input inválido (422)

### 6.5 Portal Público de Verificación

**Ruta:** `/verify/{uuid}` — sin autenticación requerida

**Muestra:**
- Imagen del badge (grande, centrada)
- Nombre del badge
- Nombre del receptor (first_name + last_name)
- Institución emisora
- Fecha de emisión
- Fecha de expiración (si aplica)
- Estado: Válido ✓ / Revocado ✗ / Expirado ⚠
- Skills/tags del badge
- Link "Ver assertion JSON" (para validadores externos Open Badges)
- Botón "Compartir en LinkedIn"

**Respuesta JSON (para validación externa):**
`GET /verify/{uuid}.json` → retorna la Open Badge assertion completa

### 6.6 Wallet del Receptor

**Ruta:** `/earner/{uuid}` — perfil público si el badge es aceptado

El receptor recibe un email con link para aceptar su badge. Al hacer clic:
1. Verificar `accept_token` válido y no expirado
2. Marcar badge como `accepted`, registrar `accepted_at`
3. Si es primera vez: pedir que confirme su nombre (pre-llenado)
4. Mostrar su wallet con todos sus badges aceptados

**Wallet pública:** `/earner/{earner_uuid}` — muestra grid de badges aceptados + nombre

### 6.7 Dashboard y Analytics

**Dashboard (`/admin`):**
- Total badges emitidos (mes actual vs mes anterior)
- Badges pendientes de aceptación
- Templates más utilizados (top 5)
- Últimas 10 emisiones

**Analytics (`/admin/analytics`):**
- Badges emitidos por mes (gráfico de barras con Chart.js, servido localmente)
- Tasa de aceptación por template
- Top earners por cantidad de badges
- CSV exportable de todos los badges emitidos (con filtros de fecha y template)

---

## 7. RUTAS COMPLETAS

```
# Públicas (sin autenticación)
GET  /                           → landing / login redirect
GET  /login                      → formulario login
POST /login                      → procesar login
GET  /logout                     → cerrar sesión
GET  /verify/{uuid}              → verificar badge público
GET  /verify/{uuid}.json         → assertion Open Badge JSON
GET  /earner/{uuid}              → wallet pública del earner
GET  /badges/{uuid}              → BadgeClass JSON-LD (Open Badges)
GET  /issuer                     → IssuerProfile JSON-LD (Open Badges)
GET  /accept/{token}             → aceptar badge (link del email)

# Panel admin (requieren sesión + rol)
GET  /admin                      → dashboard
GET  /admin/templates            → listar templates
GET  /admin/templates/new        → nuevo template
POST /admin/templates            → crear template
GET  /admin/templates/{uuid}     → ver template
GET  /admin/templates/{uuid}/edit
POST /admin/templates/{uuid}     → actualizar
POST /admin/templates/{uuid}/archive
GET  /admin/issue                → formulario emisión individual
POST /admin/issue                → emitir badge
GET  /admin/bulk-issue           → formulario emisión masiva
POST /admin/bulk-issue           → subir CSV + procesar
GET  /admin/bulk-issue/{uuid}    → estado del job
GET  /admin/badges               → listar todos los badges emitidos
GET  /admin/badges/{uuid}        → detalle de un badge
POST /admin/badges/{uuid}/revoke → revocar badge
GET  /admin/earners              → listar earners
GET  /admin/earners/{uuid}       → perfil del earner
GET  /admin/analytics            → analytics
GET  /admin/analytics/export     → exportar CSV
GET  /admin/users                → gestión de usuarios (solo superadmin)
POST /admin/users                → crear usuario
GET  /admin/api-keys             → gestionar API keys
POST /admin/api-keys             → crear API key
DELETE /admin/api-keys/{id}      → revocar API key
GET  /admin/audit                → log de auditoría

# API REST (autenticación por Bearer token)
GET  /api/v1/templates
GET  /api/v1/templates/{uuid}
POST /api/v1/badges/issue
POST /api/v1/badges/bulk-issue
GET  /api/v1/badges/{uuid}
GET  /api/v1/earners/{email}/badges
DELETE /api/v1/badges/{uuid}
```

---

## 8. CONFIGURACIÓN Y ENTORNO

### 8.1 Variables de entorno (`.env`)

```ini
# App
APP_NAME=HexBadge
APP_URL=https://badges.securehex.cl
APP_ENV=production         # production | development
APP_DEBUG=false
APP_SECRET=<random_64_chars>   # Para CSRF y tokens

# Base de datos
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=hexbadge
DB_USER=hexbadge_user
DB_PASS=<strong_password>
DB_CHARSET=utf8mb4

# Email (SMTP)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@securehex.cl
MAIL_PASSWORD=<password>
MAIL_ENCRYPTION=tls
MAIL_FROM_NAME=SecureHex Badges
MAIL_FROM_ADDRESS=noreply@securehex.cl

# Upload
UPLOAD_MAX_SIZE_MB=2
UPLOAD_PATH=public/uploads/badges/

# Rate limiting
RATE_LIMIT_LOGIN=5          # intentos por ventana
RATE_LIMIT_LOGIN_WINDOW=900 # 15 minutos en segundos
RATE_LIMIT_API=100          # requests por minuto
```

### 8.2 `.htaccess` para Apache

```apache
# public/.htaccess
Options -Indexes
Options -ExecCGI

# Redirigir todo a index.php excepto archivos reales
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Bloquear acceso a archivos sensibles
<FilesMatch "\.(env|sql|log|md|json|lock)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Bloquear ejecución de PHP en uploads
<Directory "uploads">
    php_flag engine off
    Options -ExecCGI
    <FilesMatch "\.php$">
        Order Allow,Deny
        Deny from all
    </FilesMatch>
</Directory>

# Headers de seguridad
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

---

## 9. EMAIL DE NOTIFICACIÓN

Cuando se emite un badge, el receptor recibe un email con:

**Asunto:** `Has ganado un badge: {nombre del badge} — SecureHex`

**Cuerpo (HTML):**
- Logo de SecureHex
- Imagen del badge
- Texto: "¡Felicitaciones, {nombre}! Has ganado el badge **{nombre del badge}**"
- Descripción del badge
- Botón principal: "Aceptar mi badge" → `/accept/{token}`
- Link secundario: "Ver badge" → `/verify/{uuid}`
- Footer con información del emisor

**Seguridad del email:**
- El `accept_token` expira en 30 días
- Si ya fue aceptado y el usuario vuelve a hacer clic: mostrar página de confirmación (no error)
- Re-envío disponible desde el panel admin (genera nuevo token, invalida el anterior)

---

## 10. ESTRUCTURA DEL ROUTER

```php
// src/Core/Router.php — router minimalista
class Router {
    private array $routes = [];

    public function get(string $path, array $handler): void {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, array $handler): void {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(Request $request): Response {
        $method = $request->method();
        $uri    = $request->uri();

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) continue;

            $pattern = preg_replace('/\{[a-z_]+\}/', '([a-zA-Z0-9_-]+)', $routePath);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                [$controllerClass, $action] = $handler;
                $controller = new $controllerClass();
                return $controller->$action($request, ...$matches);
            }
        }

        return new Response('Not Found', 404);
    }
}
```

---

## 11. CONVENCIONES DE CÓDIGO

- **PHP 8.3** — usar typed properties, enums nativos, match expressions, named arguments
- **PSR-12** para estilo de código
- **Clases:** PascalCase; **Métodos/variables:** camelCase; **Constantes:** UPPER_SNAKE_CASE
- **Archivos de views:** snake_case.php
- Toda función pública debe tener tipo de retorno declarado
- Nunca usar `@` para suprimir errores
- Nunca usar `extract()` con datos del usuario
- Comentar el "por qué", no el "qué"
- Todo string de respuesta al usuario debe ser escapado antes de renderizar

---

## 12. FLUJO DE INSTALACIÓN

El script `scripts/install.php` debe:
1. Verificar requisitos: PHP ≥ 8.3, extensiones `pdo_mysql`, `gd`, `fileinfo`, `openssl`
2. Leer `.env` (verificar que existe y tiene todas las variables requeridas)
3. Crear la base de datos si no existe
4. Ejecutar `database/schema.sql`
5. Crear usuario superadmin inicial (solicitar email + password por CLI)
6. Crear directorios necesarios con permisos correctos
7. Verificar que `storage/` y `config/` NO son accesibles desde web
8. Generar `APP_SECRET` si no está configurado

---

## 13. ORDEN DE IMPLEMENTACIÓN (MVP)

Claude Code debe implementar en este orden:

1. **Core infrastructure** — Router, Request, Response, Database, Session, CSRF, Logger
2. **Auth** — Login/logout, middleware de autenticación, RBAC básico (superadmin/admin/issuer)
3. **Badge Templates** — CRUD completo con upload de imagen
4. **Emisión individual** — Formulario + lógica + generación Open Badge + envío email
5. **Portal de verificación pública** — `/verify/{uuid}` HTML y JSON
6. **Wallet del earner** — Aceptación de badge + perfil público
7. **Emisión masiva CSV** — Upload, validación, procesamiento, reporte de errores
8. **API REST** — Endpoints con autenticación por API key + rate limiting
9. **Dashboard + Analytics** — Métricas básicas + exportación CSV
10. **Gestión de usuarios y API keys** — Panel admin completo
11. **Hardening final** — Revisar todos los headers, logs, permisos de archivos

---

## 14. LO QUE NO DEBE HACER CLAUDE CODE

- ❌ No usar ningún ORM ni framework (ni Eloquent, ni Doctrine, ni Twig)
- ❌ No usar Composer salvo para PHPMailer (envío de email) si es necesario — el resto en PHP puro
- ❌ No generar contraseñas temporales ni tokens con `rand()`, `mt_rand()` o `uniqid()`
- ❌ No almacenar ningún dato sensible en sesión más allá del user ID y rol
- ❌ No exponer stack traces ni mensajes de error de PHP al usuario final
- ❌ No crear rutas sin verificación de autenticación en el panel `/admin`
- ❌ No usar `$_GET`/`$_POST`/`$_FILES` directamente en controladores — solo a través de `Request`
- ❌ No omitir validación de CSRF en ningún formulario POST
- ❌ No dejar archivos de debug, `var_dump()`, `print_r()` ni `phpinfo()` en el código
