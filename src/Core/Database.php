<?php

declare(strict_types=1);

namespace HexBadge\Core;

use PDO;
use PDOStatement;
use PDOException;
use RuntimeException;

/**
 * Singleton PDO con prepared statements reales.
 *
 * Reglas de seguridad (CLAUDE.md §4.2):
 *  - EMULATE_PREPARES = false  -> prepared statements reales del servidor
 *  - ERRMODE = EXCEPTION       -> nunca silenciar errores
 *  - Toda query parametrizada; prohibido interpolar variables en SQL.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $cfg = [
            'host'    => config('database.host'),
            'port'    => config('database.port'),
            'name'    => config('database.name'),
            'user'    => config('database.user'),
            'pass'    => config('database.pass'),
            'charset' => config('database.charset', 'utf8mb4'),
        ];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Nunca exponer el detalle al usuario; loggear internamente.
            error_log('[DB] Conexión fallida: ' . $e->getMessage());
            throw new RuntimeException('Error de conexión a la base de datos');
        }
    }

    public static function getInstance(): Database
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Ejecuta una query parametrizada y devuelve el statement.
     *
     * @param array<int|string,mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Devuelve la primera fila o null.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Devuelve todas las filas.
     *
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Devuelve el valor de la primera columna de la primera fila.
     *
     * @param array<int|string,mixed> $params
     * @return mixed
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * INSERT seguro a partir de un array asociativo columna => valor.
     * Devuelve el ID autoincremental insertado.
     *
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($data as $col => $val) {
            $params[':' . $col] = $val;
        }

        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * UPDATE seguro. $where es una cláusula parametrizada (ej: 'id = ?').
     *
     * @param array<string,mixed> $data
     * @param array<int,mixed>    $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->quoteIdentifier($col) . ' = ?';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $sets),
            $where
        );

        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Valida y escapa un identificador (tabla/columna). Solo permite
     * caracteres alfanuméricos y guion bajo para impedir inyección por
     * nombres de columna provenientes de código (nunca del usuario).
     */
    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Identificador SQL inválido: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }
}
