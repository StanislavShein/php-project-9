<?php

namespace Database;

use Dotenv\Dotenv;

function getConnection()
{
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);

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

    try {
        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $queryForCreateTables = file_get_contents(__DIR__ . '/../database.sql');
        if (!$queryForCreateTables) {
            return false;
        }
        $pdo->exec($queryForCreateTables);

        return $pdo;
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
}

function getAllUrls(\PDO $pdo)
{
    $query = "SELECT * FROM urls";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    return $result->fetchAll(\PDO::FETCH_ASSOC);
}

function getIdByUrl(\PDO $pdo, string $url): string
{
    $query = "SELECT id FROM urls WHERE name='{$url}'";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    $data = $result->fetch();

    return $data['id'];
}

function getUrlRowById(\PDO $pdo, string $id)
{
    $query = "SELECT * FROM urls WHERE id={$id}";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    return $result->fetch();
}

function getLastChecks(\PDO $pdo)
{
    $query = "SELECT DISTINCT ON (url_id) url_id, created_at, status_code
              FROM url_checks
              ORDER BY url_id, created_at DESC;";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    return $result->fetchAll(\PDO::FETCH_ASSOC);
}

function getChecksByUrlId(\PDO $pdo, int $id)
{
    $query = "SELECT * FROM url_checks
              WHERE url_id={$id}
              ORDER BY id DESC";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    return $result;
}

function countUrlsByName(\PDO $pdo, string $url)
{
    $query = "SELECT COUNT(*) AS counts FROM urls WHERE name='{$url}'";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }

    return $result;
}

function insertNewUrl(\PDO $pdo, string $scheme, string $host, string $current_time): void
{
    $query = "INSERT INTO urls (name, created_at)
              VALUES ('{$scheme}://{$host}', '{$current_time}')";
    $result = $pdo->query($query);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }
}

function insertNewCheck(
    \PDO $pdo,
    int $id,
    int $statusCode,
    string $h1,
    string $title,
    string $description,
    string $current_time
): void {
    $query = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
              VALUES (?, ?, ?, ?, ?, ?)";
    $result = $pdo->prepare($query)->execute([$id, $statusCode, $h1, $title, $description, $current_time]);

    if (!$result) {
        throw new \Exception('Ошибочный запрос к базе данных');
    }
}
