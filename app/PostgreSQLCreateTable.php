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
        $queryForTableUrls = 'CREATE TABLE IF NOT EXISTS urls (
                id serial PRIMARY KEY,
                name varchar(255),
                created_at timestamp
        );';

        $queryForTableUrlChecks = 'CREATE TABLE IF NOT EXISTS url_checks (
                id serial PRIMARY KEY,
                url_id serial REFERENCES urls (id),
                status_code varchar(255),
                h1 varchar(255),
                title varchar(255),
                description varchar(255),
                created_at timestamp
        );';

        $this->pdo->exec($queryForTableUrls);

        $this->pdo->exec($queryForTableUrlChecks);

        return $this;
    }
}