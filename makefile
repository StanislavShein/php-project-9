install:
	composer install

PORT ?= 8000
start:
	sudo service postgresql start
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

clear:
	dropdb hexlet
	sudo -u postgres createdb --owner=stanislav hexlet

lint:
	composer exec --verbose phpcs -- --standard=PSR12 --colors -v public src app