<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head"><h1>Utenti</h1></div>

<?php if (!empty($notice)): ?>
    <div class="notice"><?= View::e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <table class="table">
        <thead><tr><th>Username</th><th>Ruolo</th><th>Creato</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $un = $u['username']; ?>
            <tr>
                <td><strong><?= View::e($un) ?></strong></td>
                <td>
                    <form method="post" action="<?= $base ?>/admin/users/role" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="username" value="<?= View::e($un) ?>">
                        <select name="role" onchange="this.form.submit()">
                            <option value="user"  <?= ($u['role'] ?? 'user') === 'user'  ? 'selected' : '' ?>>user</option>
                            <option value="admin" <?= ($u['role'] ?? '') === 'admin' ? 'selected' : '' ?>>admin</option>
                        </select>
                    </form>
                </td>
                <td><small><?= View::e($u['created_at']) ?></small></td>
                <td class="actions">
                    <form method="post" action="<?= $base ?>/admin/users/password" class="inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="username" value="<?= View::e($un) ?>">
                        <input type="password" name="password" placeholder="nuova password" minlength="8">
                        <button class="btn sm">Reset</button>
                    </form>
                    <form method="post" action="<?= $base ?>/admin/users/delete" class="inline"
                          onsubmit="return confirm('Eliminare <?= View::e($un) ?>?')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="username" value="<?= View::e($un) ?>">
                        <button class="btn sm danger">Elimina</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card form">
    <h3>Nuovo utente</h3>
    <form method="post" action="<?= $base ?>/admin/users" class="grid-form">
        <?= Csrf::field() ?>
        <label>Username <input name="username" required pattern="[a-zA-Z0-9._-]{2,32}"></label>
        <label>Password <input name="password" type="password" required minlength="8"></label>
        <label>Ruolo
            <select name="role"><option value="user">user</option><option value="admin">admin</option></select>
        </label>
        <div class="form-actions"><button class="btn primary">Crea utente</button></div>
    </form>
</div>
