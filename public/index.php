<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use DiDom\Document;
use GuzzleHttp\Exception\ConnectException;
use function App\Database\getConnection;
use function App\Database\getAllUrls;
use function App\Database\getIdByUrl;
use function App\Database\getUrlRowById;
use function App\Database\getLastChecks;
use function App\Database\getChecksByUrlId;
use function App\Database\countUrlsByName;
use function App\Database\insertNewUrl;
use function App\Database\insertNewCheck;


session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    return getConnection();
});
$container->set('router', function ($container) {
    return RouteContext::fromRequest($container->get('request'))->getRouteParser();
});

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) use ($router) {
    $params = [
        'activeMenu' => 'main',
        'router' => $router
    ];
    return $this->get('renderer')->render($response, 'mainpage.phtml', $params);
})->setName('mainpage');

$app->get('/urls', function ($request, $response) use ($router) {
    $messages = $this->get('flash')->getMessages();

    $pdo = $this->get('pdo');

    // запрос id url, имени url, даты последней проверки и статуса ответа
    $allUrls = getAllUrls($pdo);
    $lastChecks = getLastChecks($pdo);

    $mix = array_map(function ($url) use ($lastChecks) {
        foreach ($lastChecks as $check) {
            if ($url['id'] === $check['url_id']) {
                $url['last_check_time'] = $check['created_at'];
                $url['status_code'] = $check['status_code'];
            }
        }
        return $url;
    }, $allUrls);

    $params = [
        'flash' => $messages,
        'urls' => $mix,
        'activeMenu' => 'urls',
        'router' => $router
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) use ($router) {
    $messages = $this->get('flash')->getMessages();

    $pdo = $this->get('pdo');
    $id = $args['id'];

    // поиск строки с url по id
    $urlRow = getUrlRowById($pdo, $id);

    if (!$urlRow) {
        return $this->get('renderer')->render($response, 'error404.phtml');
    }

    $params = [
        'flash' => $messages,
        'url' => [
            'id' => $id,
            'name' => $urlRow['name'],
            'created_at' => $urlRow['created_at']
        ],
        'router' => $router
    ];

    // поиск всех проверок url по id
    $resultOfUrlChecks = getChecksByUrlId($pdo, $id);
    $params['urlChecks'] = [];
    foreach ($resultOfUrlChecks as $row) {
        $params['urlChecks'][] = [
            'id' => $row['id'],
            'status_code' => $row['status_code'],
            'h1' => $row['h1'],
            'title' => $row['title'],
            'description' => $row['description'],
            'created_at' => $row['created_at']
        ];
    }

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls', function ($request, $response) use ($router) {
    $messages = $this->get('flash')->getMessages();

    // получение и парсинг url
    $inputtedUrlData = $request->getParsedBodyParam('url', null);

    // валидация url
    $validator = new Valitron\Validator($inputtedUrlData);
    $validator->rule('required', 'name')
              ->rule('url', 'name')
              ->rule('lengthMax', 'name', 255);

    if (!($validator->validate())) {
        $invalidFeedback = $inputtedUrlData['name'] === '' ? 'URL не должен быть пустым' : 'Некорректный URL';
        $invalidUrl = $inputtedUrlData['name'];
        $params = [
            'invalidFeedback' => $invalidFeedback,
            'invalidUrl' => $invalidUrl,
            'router' => $router
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'mainpage.phtml', $params);
    } else {
        $inputtedUrl = $inputtedUrlData['name'];
        $parsedUrl = parse_url($inputtedUrl);
        $scheme = $parsedUrl['scheme'];
        $host = $parsedUrl['host'];
        $url = "{$scheme}://{$host}";
    }

    $pdo = $this->get('pdo');

    // поиск url в таблице urls, добавление, если его нет и редирект на страницу с url, если есть
    $current_time = date("Y-m-d H:i:s");

    if (countUrlsByName($pdo, $url)['counts'] === 0) {
        insertNewUrl($pdo, $url, $current_time);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
    }

    $id = getIdByUrl($pdo, $url);
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $messages = $this->get('flash')->getMessages();

    $pdo = $this->get('pdo');
    $id = $args['id'];
    $urlName = getUrlRowById($pdo, $id)['name'];

    $client = new Client(['base_uri' => $urlName]);
    try {
        $responseUrl = $client->request('GET', '/');
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
        $this->get('flash')->addMessage('warning', 'Проверка выполнена успешно, но сервер ответил с ошибкой');
        $responseUrl = $e->getResponse();
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    }

    $body = optional($responseUrl)->getBody();
    $document = new Document("{$body}");

    $statusCode = optional($responseUrl)->getStatusCode();
    $h1 = (optional($document->first('h1'))->text());
    $title = (optional($document->first('title'))->text());
    $description = (optional($document->first('meta[name=description]'))->content);
    $current_time = date("Y-m-d H:i:s");

    // добавление информации о проверке
    insertNewCheck($pdo, $id, $statusCode, $h1, $title, $description, $current_time);

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]), 302);
})->setName('urls.id.check');

$app->run();
