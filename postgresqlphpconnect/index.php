<?php

require 'vendor/autoload.php';

use PostgreSQLTutorial\Connection;

try {
    Connection::get()->connect();
    echo 'A connection to the PostgreSQL database server has been established successfully.';
} catch (\PDOException $e) {
    echo $e->getMessage();
}
