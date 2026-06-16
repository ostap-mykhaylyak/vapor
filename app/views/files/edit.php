<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; $enc = rawurlencode($instance); ?>
<div class="page-head">
    <h1>Modifica file</h1>
    <a class="btn" href="<?= $base ?>/instances/<?= $enc ?>/files?path=<?= rawurlencode($parent) ?>">← Indietro</a>
</div>
<div class="breadcrumb"><code><?= View::e($path) ?></code> · mode <?= View::e($file['mode']) ?> · uid <?= View::e($file['uid']) ?></div>

<?php if (!empty($binary)): ?>
    <div class="notice">File binario (<?= strlen($file['content']) ?> byte): visualizzazione di sola lettura, la modifica è disabilitata.</div>
    <div class="card">
        <textarea class="editor" readonly spellcheck="false"><?= View::e(mb_convert_encoding(substr($file['content'], 0, 65536), 'UTF-8', 'UTF-8')) ?></textarea>
    </div>
<?php else: ?>
<form class="card" method="post" action="<?= $base ?>/instances/<?= $enc ?>/files/write">
    <?= Csrf::field() ?>
    <input type="hidden" name="path" value="<?= View::e($path) ?>">
    <input type="hidden" name="mode" value="<?= View::e($file['mode'] ?: '0644') ?>">
    <textarea name="content" class="editor" spellcheck="false"><?= View::e($file['content']) ?></textarea>
    <div class="form-actions">
        <button class="btn primary">Salva</button>
    </div>
</form>
<?php endif; ?>
