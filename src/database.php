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
