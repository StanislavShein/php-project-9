<?php

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

$app->get('/urls', function ($request, $response) use ($router) {
    $messages = $this->get('flash')->getMessages();

    // подключение к БД
    $pdo = getConnection();

    // поиск url и даты последней проверки
    $queryForUrlsAndLastCheck = 'SELECT urls.id AS urls_id, name, MAX(url_checks.created_at) as last_check_time FROM urls
            LEFT JOIN url_checks ON urls.id = url_checks.url_id
            GROUP BY urls_id
            ORDER BY urls_id DESC';
    $resultOfUrlsAndLastCheck = $pdo->query($queryForUrlsAndLastCheck);
    $urls = [];
    foreach ($resultOfUrlsAndLastCheck as $row) {
        $urls[] = [
            'id' => $row['urls_id'],
            'name' => $row['name'],
            'lastCheckTime' => $row['last_check_time']
        ];
    }

    $params = [
        'flash' => $messages,
        'urls' => $urls
    ];

    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, $args) use ($router) {
    $messages = $this->get('flash')->getMessages();

    // подключение к БД
    $pdo = getConnection();

    $id = $args['id'];

    // поиск url по id
    $queryForUrlById = "SELECT * FROM urls WHERE id={$id}";
    $resultOfUrlById = $pdo->query($queryForUrlById)->fetch();
    $params = [
        'flash' => $messages,
        'url' => [
            'id' => $id,
            'name' => $resultOfUrlById['name'],
            'created_at' => $resultOfUrlById['created_at']
        ]
    ];

    // поиск всех проверок url по id
    $queryForUrlChecks = "SELECT * FROM url_checks WHERE url_id={$id} ORDER BY id DESC";
    $resultOfUrlChecks = $pdo->query($queryForUrlChecks);
    $urlChecks = [];
    foreach ($resultOfUrlChecks as $row) {
        $urlChecks[] = ['id' => $row['id'], 'created_at' => $row['created_at']];
    }
    $params['urlChecks'] = $urlChecks;

    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('urlId');

$app->post('/urls', function ($request, $response) use ($router) {

    // получение и парсинг url
    $inputtedUrl = $request->getParsedBodyParam('url', null);
    $url = $inputtedUrl['name'];
    $parsedUrl = parse_url($url);
    $scheme = $parsedUrl['scheme'];
    $host = $parsedUrl['host'];

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
    $pdo = getConnection();

    // поиск url в таблице urls, добавление, если его нет и редирект на страницу с url, если есть
    $current_time = date("Y-m-d H:i:s");
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

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {

    // подключение к БД
    $pdo = getConnection();

    $id = $args['id'];

    // добавление информации о проверке
    $current_time = date("Y-m-d H:i:s");
    $queryForNewCheck = "INSERT INTO url_checks (url_id, created_at) VALUES ('{$id}', '{$current_time}')";
    $pdo->query($queryForNewCheck);
    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect($router->urlFor('urlId', ['id' => $id]), 302);
});

// функция подключения к БД
function getConnection() {
    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tableCreator->createTables();
        return $pdo;
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
}

$app->run();
