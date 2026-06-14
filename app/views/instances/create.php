<?php use Vapor\Core\Csrf; $base = $config['app']['base_url'] ?? ''; ?>
<div class="page-head"><h1>Nuova istanza</h1></div>
<form class="card form" method="post" action="<?= $base ?>/instances">
    <?= Csrf::field() ?>
    <label>Nome
        <input name="name" required pattern="[a-zA-Z0-9-]+" placeholder="es. web-01">
    </label>
    <label>Immagine (alias)
        <input name="image" required placeholder="es. images:debian/12">
    </label>
    <label>Tipo
        <select name="type">
            <option value="container">Container</option>
            <option value="virtual-machine">Virtual machine</option>
        </select>
    </label>
    <label>Server immagini
        <input name="server" value="https://images.linuxcontainers.org">
    </label>
    <label class="check">
        <input type="checkbox" name="start" value="1" checked> Avvia subito dopo la creazione
    </label>
    <div class="form-actions">
        <a class="btn" href="<?= $base ?>/instances">Annulla</a>
        <button class="btn primary" type="submit">Crea</button>
    </div>
</form>
