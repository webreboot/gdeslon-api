# Общие функции Linux-раннеров (scripts/sh/*.sh) на удалённом сервере тестов (make remote-*, scripts/remote.ps1).
# Подключается через source, а не запускается.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

# Контейнеры работают под владельцем рабочей копии: vendor/ и .cache/ не становятся root-овыми,
# и следующая синхронизация (git checkout/clean) может их менять.
export UID
GID=$(id -g)
export GID

# Версии PHP для полного прогона: сервисы docker-compose.yml. composer — Alpine с самыми свежими PHP и libxml
# (поведение XMLReader зависит от версии libxml).
PHP_SERVICES=${PHP_SERVICES:-php81 php84 composer}
# Версия для быстрых прогонов (make remote-phpunit).
PHP_DEFAULT=${PHP_DEFAULT:-php84}

step() {
    echo
    echo "==> $1"
}

# Поднять контейнеры, если они не запущены (первый прогон, перезагрузка сервера, правка docker-compose.yml).
ensure_up() {
    local running wanted
    running=$(docker compose ps --status running --services 2>/dev/null | sort | xargs)
    wanted=$(docker compose config --services | sort | xargs)
    if [ "$running" != "$wanted" ] || [ docker-compose.yml -nt .cache/compose-up ]; then
        step 'docker compose up'
        docker compose up -d --remove-orphans --wait
        mkdir -p .cache && touch .cache/compose-up
    fi
}

compose_run() {
    docker compose exec -T "$@"
}

# composer install, если vendor/ ещё нет или composer.json новее него. COMPOSER_MIRROR (из локального .env,
# его передаёт scripts/remote.ps1) отключает packagist.org и подставляет зеркало.
ensure_vendor() {
    ensure_up
    if [ -f vendor/autoload.php ] && [ ! composer.json -nt vendor/autoload.php ]; then
        return
    fi
    step 'composer update'
    mkdir -p .cache/composer-home .cache/composer
    if [ -n "${COMPOSER_MIRROR:-}" ]; then
        printf '{"repositories":[{"packagist.org":false},{"type":"composer","url":"%s"}]}\n' "$COMPOSER_MIRROR" \
            > .cache/composer-home/config.json
    else
        rm -f .cache/composer-home/config.json
    fi
    compose_run composer composer update --no-interaction --no-progress --prefer-dist
    touch vendor/autoload.php
}
