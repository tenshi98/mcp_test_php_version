<?php

declare(strict_types=1);

/*
 * Punto de entrada HTTP (Streamable HTTP + OAuth 2.1).
 * Desarrollo:  php -S 127.0.0.1:8787 -t public public/index.php
 * Produccion:  apunte el DocumentRoot de Apache/Nginx a /public y fuerce HTTPS.
 */

use McpPhp\Core\Application;
use McpPhp\Http\Kernel;
use McpPhp\Http\ResponseEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

$app = Application::boot(dirname(__DIR__));
$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

(new ResponseEmitter())->emit((new Kernel($app))->handle($request));
