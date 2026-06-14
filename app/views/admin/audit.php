<?php use Vapor\Core\View; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head"><h1>Audit log</h1></div>

<form class="filters wide" method="get" action="<?= $base ?>/admin/audit">
    <label>Utente <input name="user" value="<?= View::e($fUser) ?>" placeholder="tutti"></label>
    <label>Azione <input name="action" value="<?= View::e($fAction) ?>" placeholder="es. terminal.connect"></label>
    <button class="btn">Filtra</button>
</form>

<div class="card">
    <?php if (empty($events)): ?>
        <p class="muted">Nessun evento.</p>
    <?php else: ?>
    <table class="table audit">
        <thead><tr><th>Quando</th><th>Utente</th><th>Azione</th><th>Target</th><th>Dettaglio</th><th>IP</th><th>Esito</th></tr></thead>
        <tbody>
        <?php foreach ($events as $e): ?>
            <tr class="<?= ($e['outcome'] ?? 'ok') !== 'ok' ? 'row-fail' : '' ?>">
                <td><small><?= View::e(date('Y-m-d H:i:s', (int)$e['at'])) ?></small></td>
                <td><?= View::e($e['username']) ?></td>
                <td><code><?= View::e($e['action']) ?></code></td>
                <td><?= View::e($e['target']) ?></td>
                <td><small><?= View::e($e['detail']) ?></small></td>
                <td><small><?= View::e($e['ip']) ?></small></td>
                <td>
                    <span class="badge <?= ($e['outcome'] ?? 'ok') === 'ok' ? 'on' : 'off' ?>">
                        <?= View::e($e['outcome'] ?? 'ok') ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
