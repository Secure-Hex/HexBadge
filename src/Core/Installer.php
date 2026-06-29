<?php

declare(strict_types=1);

namespace HexBadge\Core;

use PDO;
use RuntimeException;

/**
 * Lógica del asistente de instalación web (primer arranque).
 *
 * Responsable de: probar la conexión a la BD con los datos que ingresa el
 * usuario, escribir el .env, ejecutar el schema, crear el superadmin y
 * dejar el "lock" que marca la instalación como completada.
 */
final class Installer
{
    private const LOCK_FILE = BASE_PATH . '/storage/installed.lock';
    private const ENV_FILE  = BASE_PATH . '/.env';

    /**
     * La app se considera instalada si existe el lock y el .env.
     */
    public static function isInstalled(): bool
    {
        return is_file(self::LOCK_FILE) && is_file(self::ENV_FILE);
    }

    /**
     * Prueba la conexión a MySQL con las credenciales dadas.
     * Devuelve null si conecta, o un mensaje de error apto para mostrar.
     *
     * @param array<string,string> $db
     */
    public static function testConnection(array $db): ?string
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $db['host'],
            (int) $db['port']
        );
        try {
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // Verificar que el usuario pueda crear/usar la base indicada.
            $dbName = str_replace('`', '', $db['name']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            return null;
        } catch (\Throwable $e) {
            return 'No se pudo conectar a la base de datos: ' . $e->getMessage();
        }
    }

    /**
     * Ejecuta la instalación completa de forma atómica desde el punto de
     * vista del usuario: escribe .env, crea schema y superadmin, deja lock.
     *
     * @param array<string,string> $db    host, port, name, user, pass
     * @param array<string,string> $admin name, email, password
     * @param array<string,string> $app   name, url
     * @throws RuntimeException con mensaje apto para el usuario.
     */
    public static function install(array $db, array $admin, array $app): void
    {
        // 1. Conexión + creación de base de datos.
        if ($error = self::testConnection($db)) {
            throw new RuntimeException($error);
        }

        $dbName = str_replace('`', '', $db['name']);
        $dsn    = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $dbName);
        $pdo    = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // 2. Ejecutar schema (idempotente: CREATE TABLE IF NOT EXISTS).
        $schema = file_get_contents(BASE_PATH . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('No se pudo leer database/schema.sql');
        }
        $pdo->exec($schema);

        // 3. Crear superadmin si no existe ninguno.
        $hasSuper = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        if ($hasSuper === 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (uuid, name, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, \'superadmin\', 1)'
            );
            $stmt->execute([
                uuid4(),
                $admin['name'],
                strtolower($admin['email']),
                password_hash($admin['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
        }

        // 4. Escribir .env (sólo tras tener la BD lista).
        self::writeEnv($db, $app);

        // 5. Lock de instalación.
        file_put_contents(self::LOCK_FILE, date('c') . " — instalado\n");
        @chmod(self::LOCK_FILE, 0640);
    }

    /**
     * Genera y escribe el archivo .env a partir de los datos del asistente.
     *
     * @param array<string,string> $db
     * @param array<string,string> $app
     */
    private static function writeEnv(array $db, array $app): void
    {
        $secret = bin2hex(random_bytes(32));
        $url    = rtrim($app['url'], '/');

        $lines = [
            '# Generado por el asistente de instalación de HexBadge',
            '',
            '# App',
            'APP_NAME=' . self::envValue($app['name']),
            'APP_URL=' . self::envValue($url),
            'APP_EARNER_URL=' . self::envValue(rtrim($app['earner_url'] ?? '', '/')),
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_SECRET=' . $secret,
            '',
            '# Base de datos',
            'DB_HOST=' . self::envValue($db['host']),
            'DB_PORT=' . (int) $db['port'],
            'DB_NAME=' . self::envValue($db['name']),
            'DB_USER=' . self::envValue($db['user']),
            'DB_PASS=' . self::envValue($db['pass']),
            'DB_CHARSET=utf8mb4',
            '',
            '# Email (SMTP) — completar para habilitar notificaciones',
            'MAIL_HOST=',
            'MAIL_PORT=587',
            'MAIL_USERNAME=',
            'MAIL_PASSWORD=',
            'MAIL_ENCRYPTION=tls',
            'MAIL_FROM_NAME=SecureHex Badges',
            'MAIL_FROM_ADDRESS=noreply@securehex.cl',
            '',
            '# Upload',
            'UPLOAD_MAX_SIZE_MB=2',
            'UPLOAD_PATH=apps/earner/public/uploads/badges/',
            '',
            '# Rate limiting',
            'RATE_LIMIT_LOGIN=5',
            'RATE_LIMIT_LOGIN_WINDOW=900',
            'RATE_LIMIT_API=100',
            'RATE_LIMIT_VERIFY=30',
            'RATE_LIMIT_CSV=3',
            '',
        ];

        if (file_put_contents(self::ENV_FILE, implode("\n", $lines)) === false) {
            throw new RuntimeException('No se pudo escribir el archivo .env (¿permisos del directorio?)');
        }
        @chmod(self::ENV_FILE, 0640);
    }

    /**
     * Entrecomilla el valor si contiene caracteres que romperían el parser
     * de .env (espacios, #, comillas).
     */
    private static function envValue(string $value): string
    {
        if ($value === '' ) {
            return '';
        }
        if (preg_match('/[\s#"\']/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        return $value;
    }
}
