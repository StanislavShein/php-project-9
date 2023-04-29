<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use GuzzleHttp\Client;
use DiDom\Document;
use Illuminate\Support;
use function Database\getConnection as getConnection;
use function Database\getAllUrls as getAllUrls;
use function Database\getIdByUrl as getIdByUrl;
use function Database\getUrlRowById as getUrlRowById;
use function Database\getLastChecks;
use function Database\getChecksByUrlId;
use function Database\countUrlsByName;
use function Database\insertNewUrl;
use function Database\insertNewCheck;


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
    $params = ['activeMenu' => 'main'];
    return $this->get('renderer')->render($response, 'mainpage.phtml', $params);
})->setName('mainpage');

$app->get('/urls', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();

    $pdo = getConnection();
 
    // запрос id url, имени url, даты последней проверки и статуса ответа
    $allUrls = getAllUrls($pdo);
    $lastChecks = getLastChecks($pdo);
    foreach ($lastChecks as $key => $value) {
        if (array_key_exists($key, $allUrls)) {
            $allUrls[$key] = array_merge($allUrls[$key], $value);
        }
    }

    $params = [
        'flash' => $messages,
        'urls' => array_reverse($allUrls),
        'activeMenu' => 'urls'
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $messages = $this->get('flash')->getMessages();

    $pdo = getConnection();
    $id = $args['id'];

    // поиск строки с url по id
    $urlRow = getUrlRowById($pdo, $id);

    $params = [
        'flash' => $messages,
        'url' => [
            'id' => $id,
            'name' => $urlRow['name'],
            'created_at' => $urlRow['created_at']
        ]
    ];

    // поиск всех проверок url по id
    $resultOfUrlChecks = getChecksByUrlId($pdo, $id);
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

    // валидация url
    $validator = new Valitron\Validator($inputtedUrlData);
    $validator->rule('required', 'url')
              ->rule('url', 'url')
              ->rule('lengthMax', 'url', 255);
    if (!($validator->validate())) {
        $params = [
            'invalidUrl' => true,
            'inputtedUrl' => $inputtedUrlData['name']
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'mainpage.phtml', $params);
    }

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

    

    $pdo = getConnection();

    // поиск url в таблице urls, добавление, если его нет и редирект на страницу с url, если есть
    $current_time = date("Y-m-d H:i:s");

    if ((countUrlsByName($pdo, $url)->fetch())['counts'] === 0) {
        insertNewUrl($pdo, $scheme, $host, $current_time);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
    }

    $id = getIdByUrl($pdo, $url);
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {

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

    $document = new Document("{$body}");

    $h1 = (!is_null($responseUrl)) ? optional($document->first('h1'))->text() : '';
    $title = (!is_null($responseUrl)) ? optional($document->first('title'))->text() : '';
    $description = (!is_null($responseUrl)) ? optional($document->first('meta[name=description]'))->content : '';
    $current_time = date("Y-m-d H:i:s");

    // добавление информации о проверке
    insertNewCheck($pdo, $id, $statusCode, $h1, $title, $description, $current_time);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]), 302);
})->setName('urls.id.check');

$app->run();
