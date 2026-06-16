<?php use Vapor\Core\View; use Vapor\Core\Csrf; use Vapor\Core\Security; $base = $config['app']['base_url'] ?? ''; $enc = rawurlencode($instance); ?>
<div class="page-head">
    <h1>File · <?= View::e($instance) ?></h1>
    <a class="btn" href="<?= $base ?>/instances/<?= $enc ?>">← Istanza</a>
</div>

<div class="breadcrumb">
    <code><?= View::e($path) ?></code>
</div>

<div class="card">
    <div class="file-toolbar">
        <?php if ($path !== '/'): ?>
            <a class="btn sm" href="<?= $base ?>/instances/<?= $enc ?>/files?path=<?= rawurlencode($parent) ?>">⬆ Su</a>
        <?php endif; ?>
        <form class="inline" method="post" action="<?= $base ?>/instances/<?= $enc ?>/files/mkdir">
            <?= Csrf::field() ?>
            <input type="hidden" name="path" value="<?= View::e(rtrim($path,'/')) ?>/__new__placeholder" data-dir="<?= View::e(rtrim($path,'/')) ?>">
            <input name="dirname" placeholder="nuova cartella" required>
            <button class="btn sm">Crea cartella</button>
        </form>
        <form class="inline" method="post" enctype="multipart/form-data" action="<?= $base ?>/instances/<?= $enc ?>/files/upload?path=<?= rawurlencode($path) ?>">
            <?= Csrf::field() ?>
            <input type="file" name="file" required>
            <button class="btn sm">Upload</button>
        </form>
    </div>

    <table class="table files">
        <tbody>
        <?php if (($listing['type'] ?? '') !== 'directory'): ?>
            <tr><td>Questo path non è una directory.</td></tr>
        <?php elseif (empty($listing['entries'])): ?>
            <tr><td class="muted">Directory vuota.</td></tr>
        <?php else: foreach ($listing['entries'] as $e):
            $isDir  = ($e['type'] ?? 'file') === 'directory';
            $isLink = ($e['type'] ?? '') === 'symlink';
            $icon   = $isDir ? '📁' : ($isLink ? '🔗' : '📄');
            $href   = $isDir
                ? $base . '/instances/' . $enc . '/files?path=' . rawurlencode($e['path'])
                : $base . '/instances/' . $enc . '/files/read?path=' . rawurlencode($e['path']);
        ?>
            <tr>
                <td>
                    <a href="<?= $href ?>"><?= $icon ?> <?= View::e($e['name']) ?></a>
                </td>
                <td class="actions">
                    <form method="post" action="<?= $base ?>/instances/<?= $enc ?>/files/delete" onsubmit="return confirm('Eliminare <?= View::e($e['name']) ?>?')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="path" value="<?= View::e($e['path']) ?>">
                        <button class="btn sm danger">✕</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<script nonce="<?= View::e(Security::nonce()) ?>">
// La mkdir compone il path finale dalla cartella corrente + nome inserito.
document.querySelectorAll('form[action$="/files/mkdir"]').forEach(f => {
    f.addEventListener('submit', e => {
        const dir = f.querySelector('input[name=path]').dataset.dir;
        const nm  = f.querySelector('input[name=dirname]').value.trim();
        f.querySelector('input[name=path]').value = (dir === '/' ? '' : dir) + '/' + nm;
    });
});
</script>
