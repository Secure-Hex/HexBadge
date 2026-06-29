<?php

/**
 * Script de instalación de HexBadge (CLAUDE.md §12).
 *
 * Uso:  php scripts/install.php
 *
 * Verifica requisitos, crea la base de datos, ejecuta el schema, crea el
 * usuario superadmin inicial y prepara directorios/permisos.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde la línea de comandos.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use HexBadge\Core\Database;

function out(string $msg): void { fwrite(STDOUT, $msg . "\n"); }
function err(string $msg): void { fwrite(STDERR, "ERROR: " . $msg . "\n"); }
function ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    return trim((string) fgets(STDIN));
}
function askHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    system('stty -echo 2>/dev/null');
    $value = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
    return $value;
}

out("=== Instalación de HexBadge ===\n");

// 1. Requisitos --------------------------------------------------------
out("1) Verificando requisitos...");
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    err('Se requiere PHP >= 8.3. Versión actual: ' . PHP_VERSION);
    exit(1);
}
$requiredExt = ['pdo_mysql', 'gd', 'fileinfo', 'openssl'];
$missing = array_filter($requiredExt, static fn (string $e): bool => !extension_loaded($e));
if ($missing !== []) {
    err('Faltan extensiones PHP: ' . implode(', ', $missing));
    exit(1);
}
out("   OK — PHP " . PHP_VERSION . " con todas las extensiones.\n");

// 2. .env --------------------------------------------------------------
out("2) Verificando .env...");
$envPath = BASE_PATH . '/.env';
if (!is_file($envPath)) {
    err('No existe .env. Copia .env.example a .env y configúralo primero.');
    exit(1);
}
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'APP_URL'];
foreach ($required as $key) {
    if (env($key) === null || env($key) === '') {
        err("Falta la variable {$key} en .env");
        exit(1);
    }
}
out("   OK — .env presente con las variables requeridas.\n");

// 3. APP_SECRET --------------------------------------------------------
if (env('APP_SECRET') === null || env('APP_SECRET') === '') {
    out("3) APP_SECRET vacío. Genera uno con:");
    out("   php -r \"echo bin2hex(random_bytes(32));\"");
    out("   y colócalo en .env antes de continuar.\n");
    exit(1);
}
out("3) APP_SECRET configurado. OK\n");

// 4. Crear base de datos + schema -------------------------------------
out("4) Conectando a MySQL y creando base de datos...");
$dbName = (string) env('DB_NAME');
$dsn    = sprintf('mysql:host=%s;port=%s;charset=%s', env('DB_HOST'), env('DB_PORT', '3306'), env('DB_CHARSET', 'utf8mb4'));

try {
    $pdo = new PDO($dsn, (string) env('DB_USER'), (string) env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $dbName)
    ));
    $pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');

    out("   Ejecutando schema.sql...");
    $schema = file_get_contents(BASE_PATH . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('No se pudo leer schema.sql');
    }
    $pdo->exec($schema);
    out("   OK — Base de datos y tablas creadas.\n");
} catch (Throwable $e) {
    err('Fallo de base de datos: ' . $e->getMessage());
    exit(1);
}

// 5. Usuario superadmin ------------------------------------------------
out("5) Creación del usuario superadmin inicial");
$db = Database::getInstance();

$existing = (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'superadmin'");
if ($existing > 0) {
    out("   Ya existe al menos un superadmin. Se omite la creación.\n");
} else {
    $name  = ask("   Nombre: ");
    $email = strtolower(ask("   Email: "));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        err('Email inválido.');
        exit(1);
    }
    $pass  = askHidden("   Contraseña (mín. 12 caracteres): ");
    if (strlen($pass) < 12) {
        err('La contraseña debe tener al menos 12 caracteres.');
        exit(1);
    }
    $pass2 = askHidden("   Repetir contraseña: ");
    if (!hash_equals($pass, $pass2)) {
        err('Las contraseñas no coinciden.');
        exit(1);
    }

    $db->insert('users', [
        'uuid'          => uuid4(),
        'name'          => $name,
        'email'         => $email,
        'password_hash' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]),
        'role'          => 'superadmin',
        'is_active'     => 1,
    ]);
    out("   OK — Superadmin creado.\n");
}

// 6. Directorios y permisos -------------------------------------------
out("6) Preparando directorios...");
$dirs = [
    BASE_PATH . '/public/uploads/badges' => 0750,
    BASE_PATH . '/storage/logs'          => 0750,
    BASE_PATH . '/storage/temp'          => 0750,
];
foreach ($dirs as $dir => $mode) {
    if (!is_dir($dir)) {
        mkdir($dir, $mode, true);
    }
    @chmod($dir, $mode);
}
out("   OK — Directorios listos.\n");

// 7. Comprobación de exposición web -----------------------------------
out("7) Recordatorio de seguridad:");
out("   - El document root del servidor debe apuntar a public/ únicamente.");
out("   - Verifica que /src, /config, /storage y /database NO sean accesibles vía web.\n");

out("=== Instalación completada ===");
exit(0);
