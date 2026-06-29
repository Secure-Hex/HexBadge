<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Database;
use HexBadge\Models\ApiKey;

/**
 * Gestión de API keys (CLAUDE.md §4.5).
 *
 * Se guarda solo el hash SHA-256 + prefijo. El secreto se muestra una vez.
 * La verificación usa hash_equals para evitar timing attacks.
 */
final class ApiKeyService
{
    public const SCOPES = ['badges:read', 'badges:issue', 'bulk:issue', 'templates:read'];

    /**
     * Genera una API key nueva. Devuelve el secreto en claro (mostrar 1 vez).
     *
     * @param array<int,string> $scopes
     * @return array{key:string,prefix:string}
     */
    public function generate(int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $scopes = array_values(array_intersect($scopes, self::SCOPES));
        if ($scopes === []) {
            $scopes = ['badges:read'];
        }

        $secret = 'hxb_' . bin2hex(random_bytes(32));
        $prefix = substr($secret, 0, 12);
        $hash   = hash('sha256', $secret);

        Database::getInstance()->insert('api_keys', [
            'user_id'    => $userId,
            'key_hash'   => $hash,
            'key_prefix' => $prefix,
            'name'       => $name,
            'scopes'     => json_encode($scopes),
            'expires_at' => $expiresAt,
        ]);

        return ['key' => $secret, 'prefix' => $prefix];
    }

    /**
     * Verifica una key cruda. Devuelve el registro o null.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $rawKey): ?array
    {
        if (!str_starts_with($rawKey, 'hxb_')) {
            return null;
        }
        $hash = hash('sha256', $rawKey);
        $row  = ApiKey::findByHash($hash);
        if ($row === null) {
            return null;
        }
        // Comparación en tiempo constante (defensa adicional).
        if (!hash_equals((string) $row['key_hash'], $hash)) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        ApiKey::touch((int) $row['id']);
        return $row;
    }

    public function revoke(int $id, int $userId): bool
    {
        $rows = Database::getInstance()->update('api_keys', ['is_active' => 0], 'id = ? AND user_id = ?', [$id, $userId]);
        return $rows > 0;
    }

    /**
     * @param array<string,mixed> $apiKeyRow
     */
    public static function hasScope(array $apiKeyRow, string $scope): bool
    {
        $scopes = json_decode((string) ($apiKeyRow['scopes'] ?? '[]'), true);
        return is_array($scopes) && in_array($scope, $scopes, true);
    }
}
