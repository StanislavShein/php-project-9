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

$app->post('/urls', function ($request, $response) use ($router) {
    $inputedURL = $request->getParsedBodyParam('url');
    $url = $inputedURL['name'];

    /*$parsedURL = parse_url($url);
    $host = $parsedURL['host'];

    $dsn = "pgsql:host=localhost;port=5432;dbname=hexlet;";
    $pdo = new PDO($dsn, 'stanislav', 'admin', [PDO::ATTR_ERRMODE => PDO:: ERRMODE_EXCEPTION]);*/

    $validator = new Valitron\Validator(array('url' => $url));
    $validator->rule('required', 'url')
              ->rule('url', 'url')
              ->rule('lengthMax', 'url', 255);

    if ($validator->validate()) {
        return $response->write("URL валидный {$url}");
    } else {
        return $response->write("URL не валидный {$url}");
    }
});

$app->run();
