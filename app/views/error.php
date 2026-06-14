<?php use Vapor\Core\View; $base = $config['app']['base_url'] ?? ''; ?>
<div class="card error">
    <h1><?= (int)$status ?></h1>
    <pre><?= View::e($message) ?></pre>
    <a class="btn" href="<?= $base ?>/instances">← Torna alla dashboard</a>
</div>
