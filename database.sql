CREATE TABLE IF NOT EXISTS urls (
    id serial PRIMARY KEY,
    name varchar(255),
    created_at timestamp
);

CREATE TABLE IF NOT EXISTS url_checks (
    id serial PRIMARY KEY,
    url_id serial REFERENCES urls (id),
    status_code varchar(10000),
    h1 varchar(10000),
    title varchar(10000),
    description varchar(10000),
    created_at timestamp
);