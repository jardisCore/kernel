<---qa tools----->: ## -----------------------------------------------------------------------
phpunit: ## Run all tests
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests
.PHONY: phpunit

phpunit-unit: ## Run unit tests only
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests/Unit
.PHONY: phpunit-unit

phpunit-coverage: ## Run all tests with coverage text
	$(DOCKER_COMPOSE) run --rm --no-deps -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-text
.PHONY: phpunit-coverage

phpunit-coverage-html: ## Run all tests with HTML coverage
	$(DOCKER_COMPOSE) run --rm --no-deps -e PCOV_ENABLED=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php /app/tests --coverage-html tests/reports/coverage-html
.PHONY: phpunit-coverage-html

phpstan: ## Run PHPStan analysis
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpstan analyse /app/src -c phpstan.neon
.PHONY: phpstan

phpcs: ## Run coding standards
	$(DOCKER_COMPOSE) run --rm --no-deps phpcli vendor/bin/phpcs /app/src
.PHONY: phpcs
