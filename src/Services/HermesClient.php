<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Oidc;
use RuntimeException;
use Throwable;

/**
 * Talks to Hermes (the SecureHex OpenID Connect provider): discovery, JWKS and the code exchange.
 * Discovery and JWKS are cached as files under storage/oidc and served stale while Hermes fails.
 * Ported from Fides (hexcrm-php src/Services/HermesClient.php).
 */
final class HermesClient
{
    private const DISCOVERY_TTL_SECONDS = 3600;
    private const JWKS_TTL_SECONDS = 300;
    /**
     * At most one fetch per document in this window, failed or not: an unknown `kid` (the
     * back-channel endpoint is public) or a failing Hermes cannot turn into a fetch per request.
     */
    private const FETCH_INTERVAL_SECONDS = 60;
    /** Hermes waits 5 s per back-channel attempt, so a Hermes call here never takes longer. */
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 3;
    private const HTTP_TIMEOUT_SECONDS = 5;

    /**
     * Cache entries of this process, for when the cache dir is not writable.
     *
     * @var array<string, array{fetched_at: int, attempted_at: int, data: array<string, mixed>|null}>
     */
    private static array $memory = [];

    /** @var array<string, string> */
    private array $config;
    /** @var callable(string, array<string, string>|null): array<string, mixed> */
    private $fetch;
    private string $cacheDir;

    /**
     * @param (callable(string, array<string, string>|null): array<string, mixed>)|null $fetch HTTP
     *        GET (or form POST) returning decoded JSON and throwing on failure; tests inject it.
     */
    public function __construct(?callable $fetch = null, ?string $cacheDir = null)
    {
        $this->config = [
            'issuer' => (string) config('hermes.issuer', ''),
            'client_id' => (string) config('hermes.client_id', ''),
            'client_secret' => (string) config('hermes.client_secret', ''),
            'redirect_uri' => (string) config('hermes.redirect_uri', ''),
            'scopes' => (string) config('hermes.scopes', 'openid profile email roles'),
        ];
        $this->fetch = $fetch ?? $this->request(...);
        $this->cacheDir = $cacheDir ?? BASE_PATH . '/storage/oidc';
    }

    /**
     * @return array<string, string>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config['issuer'] !== '' && $this->config['client_id'] !== ''
            && $this->config['client_secret'] !== '' && $this->config['redirect_uri'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return $this->cached('discovery', self::DISCOVERY_TTL_SECONDS, null, function (): array {
            $document = ($this->fetch)(rtrim($this->config['issuer'], '/') . '/.well-known/openid-configuration', null);
            if (($document['issuer'] ?? null) !== $this->config['issuer']) {
                throw new RuntimeException('Discovery issuer does not match HERMES_ISSUER');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
                if (!is_string($document[$endpoint] ?? null) || $document[$endpoint] === '') {
                    throw new RuntimeException('Discovery document lacks ' . $endpoint);
                }
            }

            return $document;
        });
    }

    /**
     * The JWKS to verify $rawToken with: the cached one, refetched when stale or when the token
     * names a `kid` it does not know.
     *
     * @return array<string, mixed>
     */
    public function jwksFor(string $rawToken): array
    {
        $kid = Oidc::tokenKid($rawToken);
        $knowsKid = static fn (array $jwks): bool => $kid === null || in_array($kid, array_column(Oidc::usableKeys($jwks), 'kid'), true);

        return $this->cached('jwks', self::JWKS_TTL_SECONDS, $knowsKid, function (): array {
            $jwks = ($this->fetch)((string) $this->discovery()['jwks_uri'], null);
            if (Oidc::usableKeys($jwks) === []) {
                throw new RuntimeException('JWKS has no usable ES256 key');
            }

            return $jwks;
        });
    }

    /**
     * Authorization code exchange with PKCE and client_secret_basic.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return ($this->fetch)((string) $this->discovery()['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * GET (or form POST with client_secret_basic when $form is given) returning decoded JSON.
     * Throws with the HTTP status as code (0 when Hermes was not reached).
     *
     * @param array<string, string>|null $form
     * @return array<string, mixed>
     */
    private function request(string $url, ?array $form = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The curl extension is required');
        }

        $headers = ['Accept: application/json'];
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($form !== null) {
            // RFC 6749 2.3.1: id and secret are form-urlencoded before Basic encoding.
            $headers[] = 'Authorization: Basic ' . base64_encode(
                urlencode($this->config['client_id']) . ':' . urlencode($this->config['client_secret'])
            );
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body)) {
            throw new RuntimeException('Hermes request failed: ' . $error);
        }

        $data = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $code = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'unknown';
            throw new RuntimeException('Hermes responded HTTP ' . $status . ' (' . substr($code, 0, 64) . ')', $status);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Hermes returned invalid JSON');
        }

        return $data;
    }

    /**
     * Serves $name from cache while fresh (and accepted by $accepts), else fetches it, at most once
     * per FETCH_INTERVAL_SECONDS. A failed or throttled fetch falls back to the cached copy, however
     * old (stale-if-error), and only throws when there is none.
     *
     * @param (callable(array<string, mixed>): bool)|null $accepts
     * @param callable(): array<string, mixed> $fetch
     * @return array<string, mixed>
     */
    private function cached(string $name, int $ttlSeconds, ?callable $accepts, callable $fetch): array
    {
        $now = time();
        $entry = $this->readCache($name) ?? ['fetched_at' => 0, 'attempted_at' => 0, 'data' => null];
        $data = $entry['data'];

        if ($data !== null && $now - $entry['fetched_at'] < $ttlSeconds && ($accepts === null || $accepts($data))) {
            return $data;
        }

        if ($now - $entry['attempted_at'] < self::FETCH_INTERVAL_SECONDS) {
            return $data ?? throw new RuntimeException('Hermes ' . $name . ' unavailable (retry throttled)');
        }

        // Recorded before fetching, so concurrent requests during an outage do not all wait on Hermes.
        $this->writeCache($name, ['attempted_at' => $now] + $entry);

        try {
            $fresh = $fetch();
        } catch (Throwable $exception) {
            if ($data === null) {
                throw $exception;
            }
            error_log('HexBadge: Hermes ' . $name . ' refresh failed, using the cached copy from ' . date('c', $entry['fetched_at']) . ': ' . $exception->getMessage());

            return $data;
        }

        $this->writeCache($name, ['fetched_at' => $now, 'attempted_at' => $now, 'data' => $fresh]);

        return $fresh;
    }

    /**
     * The newest of the file and in-process copies.
     *
     * @return array{fetched_at: int, attempted_at: int, data: array<string, mixed>|null}|null
     */
    private function readCache(string $name): ?array
    {
        $file = $this->cacheDir . '/' . $name . '.json';
        $contents = is_readable($file) ? file_get_contents($file) : false;
        $cached = is_string($contents) ? json_decode($contents, true) : null;
        $fromFile = is_array($cached)
            && is_int($cached['fetched_at'] ?? null)
            && is_int($cached['attempted_at'] ?? null)
            && (is_array($cached['data'] ?? null) || ($cached['data'] ?? false) === null)
            ? $cached : null;
        $fromMemory = self::$memory[$file] ?? null;

        if ($fromFile === null || ($fromMemory !== null && $fromMemory['attempted_at'] >= $fromFile['attempted_at'])) {
            return $fromMemory;
        }

        return $fromFile;
    }

    /**
     * @param array{fetched_at: int, attempted_at: int, data: array<string, mixed>|null} $entry
     */
    private function writeCache(string $name, array $entry): void
    {
        $file = $this->cacheDir . '/' . $name . '.json';
        self::$memory[$file] = $entry;

        // ponytail: unwritable storage keeps the in-process copy only (per request under PHP-FPM).
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            error_log('HexBadge: Hermes cache dir ' . $this->cacheDir . ' cannot be created; caching in process only');
            return;
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($entry, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            error_log('HexBadge: Hermes ' . $name . ' cache file ' . $file . ' cannot be written; caching in process only');
        }
    }
}
