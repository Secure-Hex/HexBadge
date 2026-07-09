<?php

declare(strict_types=1);

namespace HexBadge\Services;

/**
 * Generación de assertions Open Badges 2.0 (CLAUDE.md §5).
 *
 * El identity del recipient se hashea (sha256 con salt = uuid de la
 * assertion) para no exponer el email en claro en el JSON público.
 */
final class OpenBadgeService
{
    private string $baseUrl;

    public function __construct()
    {
        // Open Badges es público: las URLs (assertion id, imagen, issuer) deben
        // apuntar al dominio público (de las personas), no al de administración.
        $this->baseUrl = rtrim((string) config('app.earner_url'), '/');
    }

    /**
     * @param array<string,mixed> $issuedBadge Fila de issued_badges (con joins de template/earner).
     * @return array<string,mixed>
     */
    public function buildAssertion(array $issuedBadge): array
    {
        $uuid         = (string) $issuedBadge['uuid'];
        $assertionUrl = $this->baseUrl . '/verify/' . $uuid;
        // El identity del Open Badge se hashea sobre el correo por el que se
        // emitió (recipient_email); así la credencial sigue validando aunque el
        // badge se haya movido a otra wallet por una fusión.
        $email        = (string) ($issuedBadge['recipient_email'] ?? $issuedBadge['earner_email'] ?? '');

        $assertion = [
            '@context'  => 'https://w3id.org/openbadges/v2',
            'type'      => 'Assertion',
            'id'        => $assertionUrl,
            'uid'       => $uuid,
            'badge'     => $this->buildBadgeClass($issuedBadge),
            'recipient' => [
                'type'     => 'email',
                'hashed'   => true,
                'salt'     => $uuid,
                'identity' => 'sha256$' . hash('sha256', strtolower($email) . $uuid),
            ],
            'issuedOn'  => $this->iso8601((string) $issuedBadge['issued_at']),
            'verification' => [
                'type'           => 'hosted',
                'allowedOrigins' => parse_url($this->baseUrl, PHP_URL_HOST),
            ],
        ];

        if (!empty($issuedBadge['expires_at'])) {
            $assertion['expires'] = $this->iso8601((string) $issuedBadge['expires_at']);
        }
        if (($issuedBadge['status'] ?? '') === 'revoked') {
            $assertion['revoked']       = true;
            $assertion['revocationReason'] = (string) ($issuedBadge['revoke_reason'] ?? 'Revocado');
        }

        return $assertion;
    }

    /**
     * @param array<string,mixed> $tpl
     * @return array<string,mixed>
     */
    public function buildBadgeClass(array $tpl): array
    {
        $templateUuid = (string) ($tpl['template_uuid'] ?? $tpl['uuid'] ?? '');
        $tags         = json_decode((string) ($tpl['skills_tags'] ?? '[]'), true);

        $class = [
            'type'        => 'BadgeClass',
            'id'          => $this->baseUrl . '/badges/' . $templateUuid,
            'name'        => (string) ($tpl['template_name'] ?? $tpl['name'] ?? ''),
            'description' => (string) ($tpl['template_description'] ?? $tpl['description'] ?? ''),
            'image'       => $this->baseUrl . '/uploads/badges/' . (string) $tpl['image_filename'],
            'criteria'    => array_filter([
                'narrative' => (string) ($tpl['criteria_text'] ?? ''),
                'id'        => $tpl['criteria_url'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'issuer'      => $this->buildIssuer($tpl),
            'tags'        => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
        ];

        return $class;
    }

    /**
     * @param array<string,mixed> $tpl
     * @return array<string,mixed>
     */
    public function buildIssuer(array $tpl = []): array
    {
        return [
            'type'  => 'Profile',
            'id'    => $this->baseUrl . '/issuer',
            'name'  => (string) ($tpl['issuer_name'] ?? config('app.name', 'SecureHex')),
            'url'   => (string) ($tpl['issuer_url'] ?? $this->baseUrl),
            'email' => (string) ($tpl['issuer_email'] ?? config('mail.from_address', '')),
        ];
    }

    private function iso8601(string $datetime): string
    {
        $ts = strtotime($datetime);
        return date('c', $ts === false ? time() : $ts);
    }
}
