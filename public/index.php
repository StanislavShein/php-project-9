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

$container->set('renderer', function () use ($container, $app) {
    $messages = $container->get('flash')->getMessages();
    $router = $app->getRouteCollector()->getRouteParser();
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates', ['flash' => $messages, 'router' => $router]);
});

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'mainpage.phtml', ['activeMenu' => 'main']);
})->setName('mainpage');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');

    $allUrls = getAllUrls($pdo);
    $lastChecks = getLastChecks($pdo);

    $checksByUrlId = collect($lastChecks)->keyBy('url_id');

    $urlChecksInfo = collect($allUrls)->map(function ($url) use ($checksByUrlId) {
        return $url + $checksByUrlId->get($url['id'], []);
    })->all();

    $params = [
        'urls' => $urlChecksInfo,
        'activeMenu' => 'urls'
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $pdo = $this->get('pdo');
    $id = (int)$args['id'];
    $url = getUrlRowById($pdo, $id);

    if ($id != $args['id']) {
        $url = false;
    }

    if (!$url) {
        return $this->get('renderer')->render($response, 'error404.phtml', ['activeMenu' => '']);
    }

    $params = [
        'url' => [
            'id' => $id,
            'name' => $url['name'],
            'created_at' => $url['created_at']
        ],
        'activeMenu' => ''
    ];

    $resultOfUrlChecks = getChecksByUrlId($pdo, $id);
    $params['urlChecks'] = array_map(function ($row) {
        return [
            'id' => $row['id'],
            'status_code' => $row['status_code'],
            'h1' => $row['h1'],
            'title' => $row['title'],
            'description' => $row['description'],
            'created_at' => $row['created_at']
        ];
    }, $resultOfUrlChecks);

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls', function ($request, $response) use ($router) {
    $inputtedUrlData = $request->getParsedBodyParam('url', null);
    $inputtedUrl = strtolower($inputtedUrlData['name']);

    $validator = new Valitron\Validator($inputtedUrlData);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');
    $validator->validate();

    if (!($validator->validate())) {
        $errors = $validator->errors();
        $params = [
            'errors' => $errors,
            'inputtedUrl' => $inputtedUrl,
            'activeMenu' => 'main'
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'mainpage.phtml', $params);
    }

    $inputtedUrl = strtolower($inputtedUrlData['name']);
    $parsedUrl = parse_url($inputtedUrl);
    $scheme = $parsedUrl['scheme'];
    $host = $parsedUrl['host'];
    $url = "{$scheme}://{$host}";

    $pdo = $this->get('pdo');
    $currentTime = date("Y-m-d H:i:s");

    if (countUrlsByName($pdo, $url)['counts'] === 0) {
        insertNewUrl($pdo, $url, $currentTime);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
    }

    $id = getIdByUrl($pdo, $url);
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {
    $pdo = $this->get('pdo');
    $id = (int)$args['id'];
    $urlName = getUrlRowById($pdo, $id)['name'];

    $client = new Client(['base_uri' => $urlName]);

    try {
        $responseUrl = $client->request('GET', '/');

        if ($responseUrl->getStatusCode() === 200) {
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');

            $body = $responseUrl->getBody();
            $document = new \DiDom\Document($body);

            $statusCode = $responseUrl->getStatusCode();
            $h1 = optional($document->first('h1'))->text();
            $title = optional($document->first('title'))->text();
            $description = optional($document->first('meta[name=description]'))->content;
            $currentTime = date("Y-m-d H:i:s");

            insertNewCheck($pdo, $id, $statusCode, $h1, $title, $description, $currentTime);
        } else {
            $this->get('flash')->addMessage('warning', 'Проверка выполнена успешно, но сервер ответил с ошибкой');
        }
    } catch (RequestException $e) {
        $this->get('flash')->addMessage('warning', 'Проверка выполнена успешно, но сервер ответил с ошибкой');
        $responseUrl = $e->getResponse();
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($router->urlFor('urls.show', ['id' => (string)$id]));
    }

    return $response->withRedirect($router->urlFor('urls.show', ['id' => (string)$id]), 302);
})->setName('urls.id.check');

$app->run();
