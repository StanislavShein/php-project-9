<?php

use Dotenv\Dotenv;
use App\PostgreSQLCreateTable;

function getConnection()
{
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();

    $dbHost = $_ENV['DB_HOST'];
    $dbPort = $_ENV['DB_PORT'];
    $dbName = $_ENV['DB_NAME'];
    $dbUser = $_ENV['DB_USERNAME'];
    $dbPassword = $_ENV['DB_PASSWORD'];

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
