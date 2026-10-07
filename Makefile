# Разработка. Хост — Windows, PHP в Docker. Полные прогоны идут на удалённом Linux-сервере
# (scripts/remote.ps1 -> scripts/sh/*.sh): каждая цель remote-* сначала синхронизирует рабочее дерево.
# Сервер задаётся в .env (REMOTE, REMOTE_DIR, COMPOSER_MIRROR) или в командной строке: make remote-test REMOTE=user@host
.PHONY: help remote-sync remote-test remote-stan remote-phpunit remote-run

POWERSHELL ?= powershell
PSFLAGS := -NoProfile -ExecutionPolicy Bypass
REMOTE ?=
REMOTE_DIR ?=
SUITE ?=
FILTER ?=
PHP ?=
CMD ?=
REMOTE_PS = $(POWERSHELL) $(PSFLAGS) -File scripts/remote.ps1 $(if $(REMOTE),-Remote $(REMOTE),) $(if $(REMOTE_DIR),-Dir $(REMOTE_DIR),)

help:
	@echo gdeslon-api: проверки на удалённом сервере (каждая цель сначала синхронизирует рабочее дерево)
	@echo   make remote-test                 - phpstan + все тесты на PHP 8.1, 8.4 и Alpine (SUITE=stan, unit, integration, install)
	@echo   make remote-stan                 - только phpstan
	@echo   make remote-phpunit FILTER=X     - один фильтр PHPUnit на PHP 8.4 (PHP=php81 - на 8.1)
	@echo   make remote-run CMD="..."        - любая команда в контейнере composer
	@echo   make remote-sync                 - только синхронизация

remote-sync:
	$(REMOTE_PS) -Action sync

remote-test:
	$(REMOTE_PS) -Action test -Arg "$(or $(SUITE),all)"

remote-stan:
	$(REMOTE_PS) -Action test -Arg stan

remote-phpunit:
	$(REMOTE_PS) -Action phpunit -Arg "$(FILTER) $(PHP)"

remote-run:
	$(REMOTE_PS) -Action run -Arg "$(CMD)"
