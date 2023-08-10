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
use function App\Database\getConnection;

session_start();

$container = new Container();
$app = AppFactory::createFromContainer($container);

$container->set('flash', new \Slim\Flash\Messages());
$container->set('pdo', getConnection());
$container->set('router', $app->getRouteCollector()->getRouteParser());
$container->set('renderer', function () use ($container) {
    $phpView = new PhpRenderer(__DIR__ . '/../templates');
    $phpView->addAttribute('flash', $container->get('flash')->getMessages());
    $phpView->addAttribute('router', $container->get('router'));
    $phpView->setLayout('layout.phtml');

    return $phpView;
});

$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'mainpage/index.phtml', ['activeMenu' => 'main']);
})->setName('mainpage.index');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');

    $urlsQuery = 'SELECT id, name FROM urls ORDER BY created_at DESC';
    $urls = $pdo->query($urlsQuery)->fetchAll(\PDO::FETCH_ASSOC);

    $lastChecksQuery = 'SELECT DISTINCT ON (url_id) url_id, created_at, status_code
                    FROM url_checks
                    ORDER BY url_id, created_at DESC;';
    $lastChecks = $pdo->query($lastChecksQuery)->fetchAll(\PDO::FETCH_ASSOC);

    $checksByUrlId = collect($lastChecks)->keyBy('url_id');

    $urlChecksInfo = collect($urls)->map(function ($url) use ($checksByUrlId) {
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

    $urlQuery = 'SELECT * FROM urls WHERE id= ?';
    $urlStmt = $pdo->prepare($urlQuery);
    $urlStmt->execute([$id]);
    $url = $urlStmt->fetch();

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

    $urlChecksQuery = 'SELECT * FROM url_checks
                       WHERE url_id = ?
                       ORDER BY id DESC';
    $urlChecksStmt = $pdo->prepare($urlChecksQuery);
    $urlChecksStmt->execute([$id]);
    $urlChecks = $urlChecksStmt->fetchAll();

    $params['urlChecks'] = array_map(function ($row) {
        return [
            'id' => $row['id'],
            'status_code' => $row['status_code'],
            'h1' => $row['h1'],
            'title' => $row['title'],
            'description' => $row['description'],
            'created_at' => $row['created_at']
        ];
    }, $urlChecks);

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$app->post('/urls', function ($request, $response) {
    $inputtedUrlData = $request->getParsedBodyParam('url', null);
    $inputtedUrl = mb_strtolower($inputtedUrlData['name']);

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

    $inputtedUrl = mb_strtolower($inputtedUrlData['name']);
    $parsedUrl = parse_url($inputtedUrl);
    $scheme = $parsedUrl['scheme'];
    $host = $parsedUrl['host'];
    $url = "{$scheme}://{$host}";

    $pdo = $this->get('pdo');
    $currentTime = date("Y-m-d H:i:s");

    $countUrlsByNameQuery = 'SELECT COUNT(*) AS counts FROM urls WHERE name = ?';
    $countUrlsByNameStmt = $pdo->prepare($countUrlsByNameQuery);
    $countUrlsByNameStmt->execute([$url]);
    $countUrlsByName = $countUrlsByNameStmt->fetch();

    if ($countUrlsByName['counts'] === 0) {
        $insertNewUrlQuery = 'INSERT INTO urls (name, created_at)
                              VALUES (?, ?)';
        $insertNewUrlStmt = $pdo->prepare($insertNewUrlQuery);
        $insertNewUrlStmt->execute([$url, $currentTime]);
        $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    } else {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
    }

    $idByUrlQuery = 'SELECT id FROM urls WHERE name = ?';
    $idByUrlStmt = $pdo->prepare($idByUrlQuery);
    $idByUrlStmt->execute([$url]);
    $id = $idByUrlStmt->fetch()['id'];

    return $response->withRedirect($this->get('router')->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->post('/urls/{id:[0-9]+}/checks', function ($request, $response, $args) {
    $pdo = $this->get('pdo');
    $id = (int)$args['id'];

    $urlQuery = 'SELECT * FROM urls WHERE id= ?';
    $urlStmt = $pdo->prepare($urlQuery);
    $urlStmt->execute([$id]);
    $url = $urlStmt->fetch()['name'];

    $client = new Client();

    try {
        $responseUrl = $client->get($url);
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

    $newCheckQuery = 'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                      VALUES (?, ?, ?, ?, ?, ?)';
    $pdo->prepare($newCheckQuery)->execute([$id, $statusCode, $h1, $title, $description, $currentTime]);

    return $response->withRedirect($this->get('router')->urlFor('urls.show', ['id' => (string)$id]), 302);
})->setName('urls.id.check');

$app->map(['GET', 'POST'], '/{routes:.+}', function ($request, $response) {
    return $this->get('renderer')->render($response, 'errors/404.phtml', ['activeMenu' => '']);
})->setName('not-found');

$app->run();
