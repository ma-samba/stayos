<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Forcer APP_ENV=test avant le chargement des .env
// (nécessaire car la variable d'environnement du container est "dev")
$_SERVER['APP_ENV'] = 'test';
$_ENV['APP_ENV']    = 'test';
putenv('APP_ENV=test');

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
