<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? '';
$name = $instance['name'] ?? '';
$status = $instance['status'] ?? 'Unknown';
$running = strtolower($status) === 'running';
$enc = rawurlencode($name);
?>
<div class="page-head">
    <h1><?= View::e($name) ?> <span class="badge <?= $running ? 'on' : 'off' ?>"><?= View::e($status) ?></span></h1>
    <div class="toolbar">
        <a class="btn" href="<?= $base ?>/instances/<?= $enc ?>/files?path=/">File manager</a>
        <a class="btn primary" href="<?= $base ?>/instances/<?= $enc ?>/terminal" target="_blank">Terminale</a>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Controllo</h3>
        <div class="btn-row">
            <?php foreach (['start'=>'Start','stop'=>'Stop','restart'=>'Restart','freeze'=>'Freeze','unfreeze'=>'Unfreeze'] as $a=>$label): ?>
                <form method="post" action="<?= $base ?>/instances/<?= $enc ?>/<?= $a ?>">
                    <?= Csrf::field() ?>
                    <button class="btn sm"><?= $label ?></button>
                </form>
            <?php endforeach; ?>
            <form method="post" action="<?= $base ?>/instances/<?= $enc ?>" onsubmit="return confirm('Eliminare <?= View::e($name) ?>?')">
                <?= Csrf::field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <button class="btn sm danger">Elimina</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Risorse</h3>
        <table class="kv">
            <tr><td>Tipo</td><td><?= View::e($instance['type'] ?? '—') ?></td></tr>
            <tr><td>Architettura</td><td><?= View::e($instance['architecture'] ?? '—') ?></td></tr>
            <tr><td>PID</td><td><?= View::e((string)($state['pid'] ?? '—')) ?></td></tr>
            <tr><td>Processi</td><td><?= View::e((string)($state['processes'] ?? '—')) ?></td></tr>
            <tr><td>Memoria</td><td><?= isset($state['memory']['usage']) ? round($state['memory']['usage']/1048576).' MiB' : '—' ?></td></tr>
        </table>
    </div>
</div>

<div class="card">
    <h3>Rete</h3>
    <table class="table">
        <thead><tr><th>Interfaccia</th><th>Indirizzo</th><th>Famiglia</th><th>Scope</th></tr></thead>
        <tbody>
        <?php foreach (($state['network'] ?? []) as $iface => $net): ?>
            <?php foreach (($net['addresses'] ?? []) as $a): ?>
                <tr>
                    <td><code><?= View::e($iface) ?></code></td>
                    <td><code><?= View::e($a['address'] ?? '') ?></code></td>
                    <td><?= View::e($a['family'] ?? '') ?></td>
                    <td><?= View::e($a['scope'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
