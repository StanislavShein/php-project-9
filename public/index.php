<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Views\PhpRenderer;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use DiDom\Document;

use function App\Database\{getConnection, getAllUrls, getIdByUrl, getUrlRowById, getLastChecks, getChecksByUrlId,
    countUrlsByName, insertNewUrl, insertNewCheck};

session_start();

$container = new Container();
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('pdo', function () {
    return getConnection();
});

$app = AppFactory::createFromContainer($container);

$container->set('router', $app->getRouteCollector()->getRouteParser());
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$container->set('renderer', function () use ($container) {
    $phpView = new PhpRenderer(__DIR__ . '/../templates');
    $phpView->addAttribute('flash', $container->get('flash')->getMessages());
    $phpView->addAttribute('router', $container->get('router'));
    $phpView->setLayout('layout.phtml');

    return $phpView;
});

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'mainpage/index.phtml', ['activeMenu' => 'main']);
})->setName('mainpage.index');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');

    $allUrls = getAllUrls($pdo);
    $lastChecks = getLastChecks($pdo);

    $checksByUrlId = collect($lastChecks)->keyBy('url_id');

    $urlChecksInfo = collect($allUrls)->map(function ($url) use ($checksByUrlId) {
        return array_merge($url, $checksByUrlId->get($url['id'], []));
    })->all();

    $params = [
        'urls' => $urlChecksInfo,
        'activeMenu' => 'urls'
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {
    $pdo = $this->get('pdo');
    $id = (int)$args['id'];
    $url = getUrlRowById($pdo, $id);

    if (!$url) {
        return $this->get('renderer')->render($response, 'errors/404.phtml', ['activeMenu' => '']);
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

$app->post('/urls', function ($request, $response) {
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

        return $this->get('renderer')->render($response->withStatus(422), 'mainpage/index.phtml', $params);
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
    return $response->withRedirect($this->get('router')->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, $args) {
    $pdo = $this->get('pdo');
    $id = (int)$args['id'];
    $urlName = getUrlRowById($pdo, $id)['name'];

    $client = new Client();

    try {
        $responseUrl = $client->get($urlName);
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (RequestException $e) {
        $responseUrl = $e->getResponse();
        if (is_null($responseUrl)) {
            return $this->get('renderer')->render($response, "errors/500.phtml", ['activeMenu' => '']);
        }
        $this->get('flash')->addMessage('warning', 'Проверка выполнена успешно, но сервер ответил с ошибкой');
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($this->get('router')->urlFor('urls.show', ['id' => (string)$id]));
    }


    $body = $responseUrl->getBody();
    $document = new Document((string) $body);

    $statusCode = optional($responseUrl)->getStatusCode();
    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->content;
    $currentTime = date("Y-m-d H:i:s");

    insertNewCheck($pdo, $id, $statusCode, $h1, $title, $description, $currentTime);

    return $response->withRedirect($this->get('router')->urlFor('urls.show', ['id' => (string)$id]), 302);
})->setName('urls.id.check');

$app->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
    return $this->get('renderer')->render($response, 'errors/404.phtml', ['activeMenu' => '']);
})->setName('not-found');

$app->run();
