<?php

namespace App;

class PostgreSQLCreateTable
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function createTables()
    {
        $queryForTableUrls =
            'CREATE TABLE IF NOT EXISTS urls (
                id serial PRIMARY KEY,
                name varchar(255),
                created_at timestamp);';

        $queryForTableUrlChecks =
            'CREATE TABLE IF NOT EXISTS url_checks (
                id serial PRIMARY KEY,
                url_id serial REFERENCES urls (id),
                status_code int,
                h1 varchar(10000),
                title varchar(10000),
                description varchar(10000),
                created_at timestamp);';

        $this->pdo->exec($queryForTableUrls);

        $this->pdo->exec($queryForTableUrlChecks);

        return $this;
    }
}
