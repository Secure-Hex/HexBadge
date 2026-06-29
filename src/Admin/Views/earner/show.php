<?php
/**
 * @var array<string,mixed>            $earner
 * @var array<int,array<string,mixed>> $badges
 * @var string                         $walletUrl
 */
$e = $earner;
?>
<h1><?= e((string) $e['display_name']) ?></h1>
<p class="muted"><?= e((string) $e['email']) ?> · <a href="<?= e($walletUrl) ?>" target="_blank" rel="noopener">Wallet pública</a></p>

<h2>Badges</h2>
<?php if (empty($badges)): ?>
    <p class="muted">Sin badges.</p>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Badge</th><th>Estado</th><th>Emitido</th></tr></thead>
        <tbody>
        <?php foreach ($badges as $b): ?>
            <tr>
                <td><a href="/admin/badges/<?= e((string) $b['uuid']) ?>"><?= e((string) $b['template_name']) ?></a></td>
                <td><span class="badge-status status-<?= e((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span></td>
                <td class="muted"><?= e((string) $b['issued_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
