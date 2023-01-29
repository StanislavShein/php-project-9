<?php

use App\Connection;
use App\PostgreSQLCreateTable;

// функция подключения к БД
function getConnection()
{
    try {
        $pdo = Connection::get()->connect();
        $tableCreator = new PostgreSQLCreateTable($pdo);
        $tableCreator->createTables();
        return $pdo;
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
}

// поиск id по url
function getIdByUrl($pdo, $url)
{
    $query = "SELECT id FROM urls WHERE name='{$url}'";
    $resultOfQuery = $pdo->query($query)->fetch();

    return $resultOfQuery['id'];
}

// поиск строки таблицы urls по id
function getUrlRowById($pdo, $id)
{
    $query = "SELECT * FROM urls WHERE id={$id}";

    return $pdo->query($query)->fetch();
}
