<?php

declare(strict_types=1);

namespace HexBadge\Services;

use HexBadge\Core\Logger;
use HexBadge\Core\Validator;
use HexBadge\Models\BadgeTemplate;

/**
 * Reglas de templates compartidas por el panel y la API/MCP: validación de
 * campos, alta y archivado. Un único lugar para que ambos caminos no diverjan.
 */
final class BadgeTemplateService
{
    /**
     * Valida y normaliza los campos de un template. Mismas reglas que el form
     * del panel: los valores llegan como strings (skills_tags en CSV).
     *
     * @param  array<string,mixed> $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function validate(array $in): array
    {
        // Igual que Request::input(): lo que no es string cuenta como ausente.
        $s = static fn (string $key, string $default = ''): string => is_string($in[$key] ?? null) ? $in[$key] : $default;
        $v = new Validator();

        $name        = $v->name($s('name'), 200);
        $description = $v->text($s('description'), 5000);
        $criteria    = $v->text($s('criteria_text'), 5000);
        $criteriaUrl = $v->url($s('criteria_url'), false);
        $expiresDays = $v->int($s('expires_days'), 1, 3650, false);
        $isPublic    = $s('is_public', '1') === '1' ? 1 : 0;
        $state       = $v->inList($s('state', 'draft'), ['draft', 'active', 'archived'], 'estado');

        // Tags: CSV -> array -> JSON.
        $tags = array_values(array_filter(array_map('trim', explode(',', $s('skills_tags'))), static fn (string $t): bool => $t !== ''));

        // Los datos del emisor (issuer_*) ya NO se editan acá: viven en la Empresa.
        return [
            'name'          => $name,
            'description'   => $description,
            'criteria_text' => $criteria,
            'criteria_url'  => $criteriaUrl,
            'skills_tags'   => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
            'expires_days'  => $expiresDays,
            'is_public'     => $isPublic,
            'state'         => $state,
        ];
    }

    /**
     * Crea el template (datos ya validados) y audita 'template.created'.
     * Devuelve el uuid.
     *
     * @param array<string,mixed> $data Salida de validate().
     */
    public static function create(array $data, int $companyId, int $userId, string $imageFilename, ?string $designRecipe, ?int $apiKeyId = null, ?string $ip = null, ?string $userAgent = null): string
    {
        $uuid = uuid4();
        BadgeTemplate::create(array_merge($data, [
            'uuid'           => $uuid,
            'created_by'     => $userId,
            'company_id'     => $companyId,
            'image_filename' => $imageFilename,
            'design_recipe'  => $designRecipe,
        ]));
        Logger::audit('template.created', $userId, 'badge_template', $uuid, ['name' => $data['name'], 'company_id' => $companyId], $apiKeyId, $ip, $userAgent);
        return $uuid;
    }

    /**
     * Archiva un template y audita 'template.archived'. Reintentar sobre uno ya
     * archivado no falla: vuelve a dejarlo igual (y vuelve a auditar).
     *
     * @param array<string,mixed> $template
     */
    public static function archive(array $template, ?int $userId, ?int $apiKeyId = null, ?string $ip = null, ?string $userAgent = null): void
    {
        BadgeTemplate::updateById((int) $template['id'], ['state' => 'archived', 'is_active' => 0]);
        $meta = isset($template['company_id']) ? ['company_id' => (int) $template['company_id']] : [];
        Logger::audit('template.archived', $userId, 'badge_template', (string) $template['uuid'], $meta, $apiKeyId, $ip, $userAgent);
    }
}
