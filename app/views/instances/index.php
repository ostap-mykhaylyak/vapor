<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head">
    <h1>Istanze</h1>
    <a class="btn primary" href="<?= $base ?>/instances/create">+ Nuova istanza</a>
</div>

<?php if (empty($instances)): ?>
    <div class="card empty">Nessuna istanza. Creane una per iniziare.</div>
<?php else: ?>
<table class="table">
    <thead>
        <tr><th>Nome</th><th>Stato</th><th>Tipo</th><th>IPv4</th><th>Immagine</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($instances as $i):
        $name   = $i['name'] ?? '';
        $status = $i['status'] ?? 'Unknown';
        $running = strtolower($status) === 'running';
        // Ricava il primo IPv4 non-loopback.
        $ipv4 = '—';
        foreach (($i['state']['network'] ?? []) as $iface => $net) {
            if ($iface === 'lo') continue;
            foreach (($net['addresses'] ?? []) as $a) {
                if (($a['family'] ?? '') === 'inet') { $ipv4 = $a['address']; break 2; }
            }
        }
    ?>
        <tr>
            <td><a href="<?= $base ?>/instances/<?= rawurlencode($name) ?>"><strong><?= View::e($name) ?></strong></a></td>
            <td><span class="badge <?= $running ? 'on' : 'off' ?>"><?= View::e($status) ?></span></td>
            <td><?= View::e($i['type'] ?? 'container') ?></td>
            <td><code><?= View::e($ipv4) ?></code></td>
            <td><small><?= View::e($i['config']['image.description'] ?? '—') ?></small></td>
            <td class="actions">
                <?php if ($running): ?>
                    <form method="post" action="<?= $base ?>/instances/<?= rawurlencode($name) ?>/stop"><?= Csrf::field() ?><button class="btn sm">Stop</button></form>
                    <a class="btn sm" href="<?= $base ?>/instances/<?= rawurlencode($name) ?>/terminal" target="_blank">Terminale</a>
                <?php else: ?>
                    <form method="post" action="<?= $base ?>/instances/<?= rawurlencode($name) ?>/start"><?= Csrf::field() ?><button class="btn sm primary">Start</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
