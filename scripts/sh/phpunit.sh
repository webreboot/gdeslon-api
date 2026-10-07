#!/usr/bin/env bash
# Быстрый прогон одного теста на одной версии PHP (фазы red/green).
# Использование: phpunit.sh <filter> [сервис PHP, по умолчанию PHP_DEFAULT]
. "$(dirname "$0")/lib.sh"
filter=${1:-}
service=${2:-$PHP_DEFAULT}

ensure_vendor
step "phpunit ${filter:+--filter $filter }($service)"
if [ -n "$filter" ]; then
    compose_run "$service" php vendor/bin/phpunit --colors=always --filter "$filter"
else
    compose_run "$service" php vendor/bin/phpunit --colors=always
fi
