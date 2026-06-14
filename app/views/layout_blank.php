<?php use Vapor\Core\View; ?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($config['app']['name']) ?> — terminale</title>
    <link rel="stylesheet" href="<?= ($config['app']['base_url'] ?? '') ?>/assets/app.css">
</head>
<body class="blank">
    <?= $content ?>
</body>
</html>
