test:
	vendor/bin/phpunit

test-file:
	vendor/bin/phpunit "$T"

composer-install:
	docker run -v ${PWD}:/app --rm composer install

composer-require:
	docker run -v ${PWD}:/app --rm composer require "$T"

.PHONY: test
