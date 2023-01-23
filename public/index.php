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
    $inputtedUrl = $request->getParsedBodyParam('url', null);
    $url = $inputtedUrl['name'];
    $parsedUrl = parse_url($url);
    $scheme = $parsedUrl['scheme'];
    $host = $parsedUrl['host'];
    $current_time = date('F j, Y \a\t g:ia');

    // валидация url
    $validator = new Valitron\Validator(array('url' => $url));
    $validator->rule('required', 'url')
              ->rule('url', 'url')
              ->rule('lengthMax', 'url', 255);

    if (!($validator->validate())) {
        $params = [
            'invalidURL' => true,
            'inputtedURL' => $inputtedUrl['name']
        ];
        return $this->get('renderer')->render($response, 'index.phtml', $params);
    }

    // подключение к БД
    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tables = $tableCreator->createTables();
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    // поиск url в таблице и добавление, если его нет
    $queryForCountingUrlsByName = "SELECT COUNT(*) AS counts FROM urls WHERE name='{$scheme}://{$host}'";
    $resultOfCountingUrlsByName = $pdo->query($queryForCountingUrlsByName);
    if (($resultOfCountingUrlsByName->fetch())['counts'] === 0) {
        $queryForInsertNewUrl = "INSERT INTO urls (name, created_at)
                                 VALUES ('{$scheme}://{$host}', '{$current_time}')";
        $pdo->query($queryForInsertNewUrl);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $this->get('flash')->addMessage('warning', 'Страница уже существует');
        $queryForSearchingIdByUrl = "SELECT id FROM urls WHERE name='{$scheme}://{$host}'";
        $resultOfSearchingIdByUrl = $pdo->query($queryForSearchingIdByUrl)->fetch();
        $id = $resultOfSearchingIdByUrl['id'];
        return $response->withRedirect("/urls/{$id}");
    }

    return $response->withRedirect($router->urlFor('mainpage'), 302);
});

$app->get('/urls', function ($request, $response) use ($router) {
    // подключение к БД
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

$app->get('/urls/{id}', function ($request, $response, $args) use ($router) {
    // подключение к БД
    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tables = $tableCreator->createTables();
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    $id = $args['id'];
    $queryForUrlById = "SELECT * FROM urls WHERE id={$id}";
    $urlData = $pdo->query($queryForUrlById)->fetch();
    $params = ['url' => ['id' => $id, 'name' => $urlData['name'], 'created_at' => $urlData['created_at']]];

    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('urlId');

$app->run();
