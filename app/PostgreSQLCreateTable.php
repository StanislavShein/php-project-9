<?php

namespace App;
/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class PostgreSQLCreateTable {

    /**
     * объект PDO 
     * @var \PDO
     */
    private $pdo;

    /**
     * инициализация объекта с объектом \PDO 
     * @тип параметра $pdo
     */
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * создание таблиц 
     */
    public function createTables() {
        $sql = 'CREATE TABLE IF NOT EXISTS urls (
                id serial PRIMARY KEY,
                name varchar(255),
                created_at timestamp
        );';

        $this->pdo->exec($sql);

        return $this;
    }
}