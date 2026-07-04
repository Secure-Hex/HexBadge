<?php
/**
 * @var array<string,mixed> $template
 * @var array<int,string>   $tags
 */
use HexBadge\Core\CSRF;

$t = $template;
?>
<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap">
    <img src="<?= e(badge_image_url((string) $t['image_filename'])) ?>" alt="<?= e((string) $t['name']) ?>"
         style="width:160px;height:160px;object-fit:contain;background:var(--surface);border-radius:12px;padding:8px">
    <div style="flex:1;min-width:280px">
        <h1 style="margin-top:0"><?= e((string) $t['name']) ?></h1>
        <p><span class="badge-status status-<?= $t['state'] === 'active' ? 'accepted' : ($t['state'] === 'archived' ? 'revoked' : 'pending') ?>"><?= e((string) $t['state']) ?></span>
           · <?= e((string) $t['badges_issued']) ?> emitidos</p>
        <p><?= e((string) $t['description']) ?></p>

        <h3>Criterios</h3>
        <p><?= nl2br(e((string) $t['criteria_text'])) ?></p>
        <?php if (!empty($t['criteria_url'])): ?>
            <p><a href="<?= e((string) $t['criteria_url']) ?>" target="_blank" rel="noopener">Ver criterios</a></p>
        <?php endif; ?>

        <?php if (!empty($tags)): ?>
            <p>
                <?php foreach ($tags as $tag): ?>
                    <span class="badge-status status-pending"><?= e($tag) ?></span>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <p class="muted">Emisor: <?= e((string) $t['issuer_name']) ?> · <?= e((string) $t['issuer_email']) ?>
            <?php if (!empty($t['expires_days'])): ?> · Expira a los <?= e((string) $t['expires_days']) ?> días<?php endif; ?>
        </p>
        <p class="muted">LinkedIn Organization ID:
            <?php if (!empty($t['linkedin_org_id'])): ?><code><?= e((string) $t['linkedin_org_id']) ?></code><?php else: ?><span>— sin configurar (se usa solo el nombre del emisor)</span><?php endif; ?>
        </p>
        <p class="muted">ID del template <span style="font-size:.82em">(para la columna <code>badge_template_id</code> en emisión masiva)</span>:<br>
            <code style="user-select:all"><?= e((string) $t['uuid']) ?></code>
        </p>

        <?php
        $linked     = !empty($t['certificate_template_id']);
        $eff        = \HexBadge\Models\BadgeTemplate::withEffectiveCert($t);
        $hasCertImg = !empty($eff['certificate_filename']);
        $hasCertCfg = $hasCertImg && !empty($eff['certificate_config']);
        ?>
        <p class="muted">Certificado / diploma:
            <?php if ($linked): ?><span class="badge-status status-accepted">plantilla guardada<?= $hasCertCfg ? '' : ' (sin marcar)' ?></span>
            <?php elseif ($hasCertCfg): ?><span class="badge-status status-accepted">configurado</span>
            <?php elseif ($hasCertImg): ?><span class="badge-status status-pending">plantilla cargada, falta marcar</span>
            <?php else: ?><span>— sin diploma (elegí uno al editar el template)</span><?php endif; ?>
        </p>

        <div style="display:flex;gap:.6rem;margin-top:1rem;flex-wrap:wrap">
            <a class="btn" href="/admin/templates/<?= e((string) $t['uuid']) ?>/edit">Editar</a>
            <?php if ($hasCertImg && !$linked): ?>
                <a class="btn" href="/admin/templates/<?= e((string) $t['uuid']) ?>/certificate"><?= $hasCertCfg ? 'Reconfigurar certificado' : 'Marcar certificado' ?></a>
            <?php endif; ?>
            <?php if ($linked): ?>
                <a class="btn" href="/admin/diploma-templates">Editar plantilla de diploma</a>
            <?php endif; ?>
            <?php if ($hasCertCfg): ?>
                <a class="btn" href="/admin/templates/<?= e((string) $t['uuid']) ?>/certificates">Descargar diplomas</a>
            <?php endif; ?>
            <?php if ($t['state'] === 'active'): ?>
                <a class="btn btn-primary" href="/admin/issue?template=<?= e((string) $t['uuid']) ?>">Emitir este badge</a>
            <?php endif; ?>
            <?php if ($t['state'] !== 'archived'): ?>
                <form method="POST" action="/admin/templates/<?= e((string) $t['uuid']) ?>/archive" style="display:inline"
                      onsubmit="return confirm('¿Archivar este template?')">
                    <?= CSRF::field() ?>
                    <button type="submit" class="btn btn-danger">Archivar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
