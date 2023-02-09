<?php

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
        $pdo = new PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $queryForCreateTables = file_get_contents(__DIR__ . '/../database.sql');
        $pdo->exec($queryForCreateTables);

        return $pdo;
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
}

function getIdByUrl($pdo, $url)
{
    $query = "SELECT id FROM urls WHERE name='{$url}'";
    $resultOfQuery = $pdo->query($query)->fetch();

    return $resultOfQuery['id'];
}

function getUrlRowById($pdo, $id)
{
    $query = "SELECT * FROM urls WHERE id={$id}";

    return $pdo->query($query)->fetch();
}
