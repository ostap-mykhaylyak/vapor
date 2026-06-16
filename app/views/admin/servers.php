<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head"><h1>Server Incus/LXD</h1></div>

<?php if (!empty($notice)): ?>
    <div class="notice"><?= View::e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <?php if (empty($servers)): ?>
        <p class="muted">Nessun server. Aggiungine uno qui sotto.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Nome</th><th>URL</th><th>Project</th><th>Verify TLS</th><th>Default</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($servers as $s): ?>
            <tr>
                <td><strong><?= View::e($s['name']) ?></strong></td>
                <td><code><?= View::e($s['url']) ?></code></td>
                <td><?= View::e($s['project']) ?></td>
                <td><?= !empty($s['verify']) ? 'sì' : 'no' ?></td>
                <td>
                    <?php if (!empty($s['is_default'])): ?>
                        <span class="badge on">default</span>
                    <?php else: ?>
                        <form method="post" action="<?= $base ?>/admin/servers/default" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn sm">Rendi default</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <form method="post" action="<?= $base ?>/admin/servers/delete" class="inline"
                          onsubmit="return confirm('Eliminare il server <?= View::e($s['name']) ?>? (i container non vengono toccati)')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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
    <h3>Aggiungi server</h3>
    <p class="muted">
        Sull'host Incus genera prima un trust token:
        <code>incus config trust add &lt;nome&gt;</code>, poi incollalo qui.
        Vapor genera un certificato client e si registra automaticamente.
    </p>
    <form method="post" action="<?= $base ?>/admin/servers" class="grid-form">
        <?= Csrf::field() ?>
        <label>Nome <input name="name" required pattern="[a-zA-Z0-9._-]{2,40}" placeholder="es. prod-01"></label>
        <label>URL <input name="url" required placeholder="https://host:8443"></label>
        <label>Project <input name="project" value="default"></label>
        <label>Trust token <input name="token" required placeholder="incolla il token"></label>
        <label class="check"><input type="checkbox" name="verify" value="1"> Verifica certificato TLS del server</label>
        <p class="muted" style="grid-column:1/-1">
            Con la verifica attiva, Vapor recupera e <strong>fissa</strong> (pinning) il
            certificato del server: protegge dal MITM anche con certificati self-signed
            di Incus. Il server dev'essere raggiungibile al momento dell'aggiunta.
        </p>
        <div class="form-actions"><button class="btn primary">Aggiungi e registra</button></div>
    </form>
</div>
