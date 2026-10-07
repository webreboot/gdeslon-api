#!/usr/bin/env bash
# Проверки на удалённом сервере. Использование: test.sh [all|stan|unit|integration|install]
#   all          phpstan + все тесты на каждой версии PHP из PHP_SERVICES (по умолчанию)
#   stan         только phpstan
#   unit         тесты Unit на каждой версии PHP
#   integration  тесты Integration на каждой версии PHP
#   install      только установить зависимости
. "$(dirname "$0")/lib.sh"
suite=${1:-all}

ensure_vendor

stan() {
    step 'phpstan'
    compose_run composer php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
}

phpunit_all() {
    local status=0 service
    for service in $PHP_SERVICES; do
        step "phpunit $* ($service)"
        compose_run "$service" php vendor/bin/phpunit --colors=always "$@" || status=1
    done
    return $status
}

status=0
case "$suite" in
    install) echo 'Зависимости установлены: vendor/' ;;
    stan) stan || status=1 ;;
    unit) phpunit_all --testsuite Unit || status=1 ;;
    integration) phpunit_all --testsuite Integration || status=1 ;;
    all) stan || status=1; phpunit_all || status=1 ;;
    *) echo "Неизвестный набор: $suite (all|stan|unit|integration|install)" >&2; exit 2 ;;
esac
exit $status
