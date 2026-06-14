<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="login-wrap">
    <form class="login-card" method="post" action="<?= $base ?>/login">
        <?= Csrf::field() ?>
        <div class="login-brand"><span class="logo">≋</span> <?= View::e($config['app']['name']) ?></div>
        <p class="login-sub">Accedi per gestire le tue istanze Incus</p>

        <?php if (!empty($error)): ?>
            <div class="login-error"><?= View::e($error) ?></div>
        <?php endif; ?>

        <label>Utente
            <input name="username" autofocus required autocomplete="username">
        </label>
        <label>Password
            <input name="password" type="password" required autocomplete="current-password">
        </label>
        <button class="btn primary block" type="submit">Accedi</button>
    </form>
</div>
