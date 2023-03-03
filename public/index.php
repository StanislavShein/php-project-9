<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use GuzzleHttp\Client;
use DiDom\Document;

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

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'mainpage.phtml');
})->setName('mainpage');

$app->get('/urls', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();

    $pdo = getConnection();

    // запрос id url, имени url, даты последней проверки и статуса ответа
    $queryForIdNameLastCheckAndStatusCode =
           "SELECT urls.id AS urls_id, name, last_check_table.created_at AS last_check_time, status_code
            FROM urls
            LEFT JOIN
                (SELECT max_id_table.url_id AS url_id, created_at, status_code
                FROM
                    (SELECT url_id, MAX(id) AS max_id
                    FROM url_checks
                    GROUP BY url_id) AS max_id_table
                LEFT JOIN url_checks 
                ON max_id = url_checks.id) AS last_check_table
            ON urls.id = last_check_table.url_id
            ORDER BY urls_id DESC";

    $IdNameLastCheckAndStatusCode = $pdo->query($queryForIdNameLastCheckAndStatusCode);
    $urls = [];
    foreach ($IdNameLastCheckAndStatusCode as $row) {
        $urls[] = [
            'id' => $row['urls_id'],
            'name' => $row['name'],
            'lastCheckTime' => $row['last_check_time'],
            'statusCode' => $row['status_code']
        ];
    }

    $params = [
        'flash' => $messages,
        'urls' => $urls
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $messages = $this->get('flash')->getMessages();

    $pdo = getConnection();

    $id = $args['id'];

    // поиск строки с url по id
    $urlRow = getUrlRowById($pdo, $id);
    if (is_null($urlRow)) {
        throw new \Exception("Страница не найдена!");
    }

    $params = [
        'flash' => $messages,
        'url' => [
            'id' => $id,
            'name' => $urlRow['name'],
            'created_at' => $urlRow['created_at']
        ]
    ];

    // поиск всех проверок url по id
    $queryForUrlChecks = "SELECT * FROM url_checks
                          WHERE url_id={$id}
                          ORDER BY id DESC";
    $resultOfUrlChecks = $pdo->query($queryForUrlChecks);
    $urlChecks = [];
    foreach ($resultOfUrlChecks as $row) {
        $urlChecks[] = [
            'id' => $row['id'],
            'status_code' => $row['status_code'],
            'h1' => $row['h1'],
            'title' => $row['title'],
            'description' => $row['description'],
            'created_at' => $row['created_at']
        ];
    }
    $params['urlChecks'] = $urlChecks;

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls', function ($request, $response) use ($router) {

    // получение и парсинг url
    $inputtedUrlData = $request->getParsedBodyParam('url', null);

    if ($inputtedUrlData['name'] === '') {
        $params = ['invalidUrl' => true, 'inputtedUrl' => ''];

        return $this->get('renderer')->render($response->withStatus(422), 'mainpage.phtml', $params);
    } else {
        $inputtedUrl = $inputtedUrlData['name'];
        $parsedUrl = parse_url($inputtedUrl);
        $scheme = $parsedUrl['scheme'];
        $host = $parsedUrl['host'];
        $url = "{$scheme}://{$host}";
    }

    // валидация url
    $validator = new Valitron\Validator(array('url' => $url));
    $validator->rule('required', 'url')
              ->rule('url', 'url')
              ->rule('lengthMax', 'url', 255);
    if (!($validator->validate())) {
        $params = [
            'invalidUrl' => true,
            'inputtedUrl' => $inputtedUrl
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'mainpage.phtml', $params);
    }

    // подключение к БД
    $pdo = getConnection();

    // поиск url в таблице urls, добавление, если его нет и редирект на страницу с url, если есть
    $current_time = date("Y-m-d H:i:s");
    $queryForCountingUrlsByName = "SELECT COUNT(*) AS counts FROM urls WHERE name='{$url}'";
    $resultOfCountingUrlsByName = $pdo->query($queryForCountingUrlsByName);
    if (($resultOfCountingUrlsByName->fetch())['counts'] === 0) {
        $queryForInsertNewUrl = "INSERT INTO urls (name, created_at)
                                 VALUES ('{$scheme}://{$host}', '{$current_time}')";
        $pdo->query($queryForInsertNewUrl);
        $id = getIdByUrl($pdo, $url);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

        return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    } else {
        $id = getIdByUrl($pdo, $url);
        $this->get('flash')->addMessage('success', 'Страница уже существует');

        return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    }
})->setName('url.add');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {

    // подключение к БД
    $pdo = getConnection();

    $id = $args['id'];

    $urlName = getUrlRowById($pdo, $id)['name'];

    // проверка на код ответа status_code
    $client = new Client(['base_uri' => $urlName]);
    try {
        $responseUrl = $client->request('GET', '/');
    } catch (Exception) {
        $this->get('flash')->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withStatus(404)->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    }
    $statusCode = $responseUrl->getStatusCode();

    // проверка на содержимое
    $body = $responseUrl->getBody();
    $h1 = '';
    $title = '';
    $description = '';

    $document = new Document("{$body}");

    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->content;
    $current_time = date("Y-m-d H:i:s");

    // добавление информации о проверке
    $sqlForNewCheck = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)";
    $queryForNewCheck = $pdo->prepare($sqlForNewCheck);
    $queryForNewCheck->execute([$id, $statusCode, $h1, $title, $description, $current_time]);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]), 302);
})->setName('url.check');

$app->run();
