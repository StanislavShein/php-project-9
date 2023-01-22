<?php

// Подключение автозагрузки через composer
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use App\Connection;
use App\PostgreSQLCreateTable;

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) use ($router) {
    $messages = $this->get('flash')->getMessages();
    $params = ['flash' => $messages];

    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('mainpage');

$app->post('/urls', function ($request, $response) use ($router) {
    $inputtedURL = $request->getParsedBodyParam('url');
    $url = $inputtedURL['name'];
    $current_time = date('F j, Y \a\t g:ia');

    if ($inputtedURL === null) {
        $this->get('flash')->addMessage('warning', 'Нужно вести адрес веб-страницы');
        return $response->withRedirect($router->urlFor('mainpage'));
    }

    $validator = new Valitron\Validator(array('url' => $url));
    $validator->rule('required', 'url')
              ->rule('url', 'url')
              ->rule('lengthMax', 'url', 255);

    if (!($validator->validate())) {
        $params = [
            'invalidURL' => true,
            'inputtedURL' => $inputtedURL['name']
        ];
        return $this->get('renderer')->render($response, 'index.phtml', $params);
    }

    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tables = $tableCreator->createTables();
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    $insertNewUrl = 'INSERT INTO urls (name, created_at)
                     VALUES (:name, :time)';
    $query = $pdo->prepare($insertNewUrl);
    $query->execute(['name' => $url, 'time' => $current_time]);

    return $response->withRedirect($router->urlFor('mainpage'), 302);
});

$app->get('/urls', function ($request, $response) use ($router) {
    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tables = $tableCreator->createTables();
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    $sql = 'SELECT id, name FROM urls ORDER BY id DESC';
    $query = $pdo->query($sql);
    $urls = [];
    foreach ($query as $row) {
        $urls[] = ['id' => $row['id'], 'name' => $row['name']];
    }
    $params = [
        'urls' => $urls
    ];

    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->run();
