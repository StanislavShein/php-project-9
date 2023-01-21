<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
});

$app->post('/urls', function ($request, $response) {
    $data = $request->getParsedBodyParam('url');

    /*$dsn = "pgsql:host=localhost;port=5432;dbname=hexlet;";
    $pdo = new PDO($dsn, 'stanislav', 'admin', [PDO::ATTR_ERRMODE => PDO:: ERRMODE_EXCEPTION]);*/

});

$app->run();
