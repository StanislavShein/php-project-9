<?php

namespace App\Database;

use Dotenv\Dotenv;

function getConnection()
{
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    if (!$databaseUrl) {
        throw new \Exception('Ошибочный запрос к конфигурации базы данных');
    }

    $dbHost = $databaseUrl['host'];
    $dbPort = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');
    $dbUser = $databaseUrl['user'];
    $dbPassword = $databaseUrl['pass'];

    $conStr = "pgsql:
        host={$dbHost};
        port={$dbPort};
        dbname={$dbName};
        user={$dbUser};
        password={$dbPassword}";

    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function getAllUrls(\PDO $pdo)
{
    $query = 'SELECT id, name FROM urls ORDER BY created_at DESC';
    $result = $pdo->query($query);

    return $result->fetchAll(\PDO::FETCH_ASSOC);
}

function getIdByUrl(\PDO $pdo, string $url): string
{
    $query = 'SELECT id FROM urls WHERE name = ?';
    $smtp = $pdo->prepare($query);
    $smtp->execute([$url]);
    $data = $smtp->fetch();

    return $data['id'];
}

function getUrlRowById(\PDO $pdo, int $id)
{
    $query = 'SELECT * FROM urls WHERE id= ?';
    $smtp = $pdo->prepare($query);
    $smtp->execute([$id]);

    return $smtp->fetch();
}

function getLastChecks(\PDO $pdo)
{
    $query = 'SELECT DISTINCT ON (url_id) url_id, created_at, status_code
              FROM url_checks
              ORDER BY url_id, created_at DESC;';
    $result = $pdo->query($query);

    return $result->fetchAll(\PDO::FETCH_ASSOC);
}

function getChecksByUrlId(\PDO $pdo, int $id)
{
    $query = 'SELECT * FROM url_checks
              WHERE url_id = ?
              ORDER BY id DESC';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);

    return $stmt->fetchAll();
}

function countUrlsByName(\PDO $pdo, string $url)
{
    $query = 'SELECT COUNT(*) AS counts FROM urls WHERE name = ?';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$url]);

    return $stmt->fetch();
}

function insertNewUrl(\PDO $pdo, string $url, string $current_time): void
{
    $query = 'INSERT INTO urls (name, created_at)
              VALUES (?, ?)';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$url, $current_time]);
}

function insertNewCheck(
    \PDO $pdo,
    int $id,
    int|null $statusCode,
    string|null $h1,
    string|null $title,
    string|null $description,
    string $current_time
): void {
    $query = 'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
              VALUES (?, ?, ?, ?, ?, ?)';
    $pdo->prepare($query)->execute([$id, $statusCode, $h1, $title, $description, $current_time]);
}
