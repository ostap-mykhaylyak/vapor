<?php
/**
 * Front controller di Vapor.
 */
require __DIR__ . '/../app/autoload.php';

use Vapor\Core\App;
use Vapor\Core\Request;

$config = require __DIR__ . '/../config/config.php';
$app    = new App($config);

// Registra le rotte.
(require __DIR__ . '/../config/routes.php')($app->router());

$app->run(Request::fromGlobals());
