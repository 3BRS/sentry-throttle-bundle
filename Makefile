.PHONY: help up down restart build bash composer install update update-lowest test phpunit phpunit-unit phpunit-integration phpunit-coverage audit ecs ecs-fix phpstan deptrac rector rector-fix logs ps

PHP_VERSION ?= 8.5
export PHP_VERSION

DOCKER_COMPOSE = docker compose
PHP = $(DOCKER_COMPOSE) exec php

help: ## Show help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

up: ## Start Docker containers (make up PHP_VERSION=8.1 for the lowest supported PHP)
	$(DOCKER_COMPOSE) up -d

down: ## Stop Docker containers
	$(DOCKER_COMPOSE) down

restart: down up ## Restart Docker containers

build: ## Rebuild Docker images
	$(DOCKER_COMPOSE) build --no-cache

bash: ## Connect to PHP container
	$(PHP) bash

composer: ## Run composer command (usage: make composer CMD="require foo/bar")
	$(PHP) composer $(CMD)

install: ## Install composer dependencies
	$(PHP) composer install

update: ## Update composer dependencies
	$(PHP) composer update

update-lowest: ## Update composer dependencies to the lowest supported versions
	$(DOCKER_COMPOSE) exec -e COMPOSER_NO_BLOCKING=1 -e COMPOSER_NO_SECURITY_BLOCKING=1 php composer update --prefer-lowest --prefer-stable

test: phpunit ecs phpstan deptrac rector ## Run all checks (PHPUnit, ECS, PHPStan, Deptrac, Rector)

phpunit: ## Run PHPUnit tests
	$(PHP) vendor/bin/phpunit

phpunit-unit: ## Run only unit tests
	$(PHP) composer test-unit

phpunit-integration: ## Run only integration tests
	$(PHP) composer test-integration

phpunit-coverage: ## Run PHPUnit with HTML coverage report in var/coverage
	$(DOCKER_COMPOSE) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html=var/coverage

audit: ## Check dependencies for security vulnerabilities
	$(PHP) composer audit

ecs: ## Check code style
	$(PHP) composer ecs

ecs-fix: ## Fix code style
	$(PHP) composer ecs-fix

phpstan: ## Run PHPStan static analysis
	$(PHP) composer phpstan

deptrac: ## Run Deptrac architecture analysis
	$(PHP) composer deptrac

rector: ## Check code for automated refactoring opportunities
	$(PHP) composer rector

rector-fix: ## Apply automated refactorings
	$(PHP) composer rector-fix

logs: ## Show container logs
	$(DOCKER_COMPOSE) logs -f

ps: ## Show running containers
	$(DOCKER_COMPOSE) ps
