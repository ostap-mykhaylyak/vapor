<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head"><h1>Network forward</h1></div>

<form class="filters" method="get" action="<?= $base ?>/forwards">
    <label>Rete
        <select name="network" onchange="this.form.submit()">
            <?php foreach ($networks as $n): $nn = $n['name'] ?? ''; ?>
                <option value="<?= View::e($nn) ?>" <?= $nn === $selected ? 'selected' : '' ?>>
                    <?= View::e($nn) ?> (<?= View::e($n['type'] ?? '') ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<div class="card">
    <h3>Forward su <code><?= View::e((string)$selected) ?></code></h3>
    <?php if (empty($forwards)): ?>
        <p class="muted">Nessun forward configurato su questa rete.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Listen address</th><th>Descrizione</th><th>Porte</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($forwards as $f): ?>
            <tr>
                <td><code><?= View::e($f['listen_address'] ?? '') ?></code></td>
                <td><?= View::e($f['description'] ?? '') ?></td>
                <td>
                    <?php foreach (($f['ports'] ?? []) as $p): ?>
                        <div class="port">
                            <?= View::e($p['protocol'] ?? 'tcp') ?>
                            <?= View::e($p['listen_port'] ?? '') ?>
                            → <?= View::e($p['target_address'] ?? '') ?>:<?= View::e($p['target_port'] ?? '') ?>
                        </div>
                    <?php endforeach; ?>
                </td>
                <td>
                    <form method="post" action="<?= $base ?>/forwards/delete" onsubmit="return confirm('Eliminare il forward?')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="network" value="<?= View::e((string)$selected) ?>">
                        <input type="hidden" name="listen_address" value="<?= View::e($f['listen_address'] ?? '') ?>">
                        <button class="btn sm danger">Elimina</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card form">
    <h3>Nuovo forward</h3>
    <form method="post" action="<?= $base ?>/forwards" class="grid-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="network" value="<?= View::e((string)$selected) ?>">
        <label>Listen address <input name="listen_address" required placeholder="es. 192.0.2.10"></label>
        <label>Descrizione <input name="description" placeholder="opzionale"></label>
        <label>Protocollo
            <select name="protocol"><option>tcp</option><option>udp</option></select>
        </label>
        <label>Listen port <input name="listen_port" placeholder="es. 80"></label>
        <label>Target address <input name="target_address" placeholder="es. 10.0.0.5"></label>
        <label>Target port <input name="target_port" placeholder="es. 8080"></label>
        <div class="form-actions"><button class="btn primary">Crea forward</button></div>
    </form>
</div>
