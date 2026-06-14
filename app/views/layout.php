<?php use Vapor\Core\View; use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; $authUser = $authUser ?? null; $isAdmin = $isAdmin ?? false; ?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($config['app']['name']) ?></title>
    <link rel="stylesheet" href="<?= $base ?>/assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= $base ?>/instances">
        <span class="logo">≋</span> <?= View::e($config['app']['name']) ?>
    </a>
    <nav>
        <a href="<?= $base ?>/instances">Istanze</a>
        <?php if (!empty($isAdmin)): ?>
            <a href="<?= $base ?>/forwards">Forward</a>
            <a href="<?= $base ?>/admin/users">Utenti</a>
            <a href="<?= $base ?>/admin/audit">Audit</a>
        <?php endif; ?>
    </nav>
    <span class="project">project: <?= View::e($config['incus']['project']) ?></span>
    <?php if (!empty($authUser)): ?>
        <div class="user-menu">
            <span class="user"><?= View::e($authUser['username']) ?><?= !empty($isAdmin) ? ' · admin' : '' ?></span>
            <form method="post" action="<?= $base ?>/logout">
                <?= Csrf::field() ?>
                <button class="btn sm">Esci</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<main class="container">
    <?= $content ?>
</main>
<script src="<?= $base ?>/assets/app.js" defer></script>
</body>
</html>
