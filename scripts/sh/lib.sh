set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

# контейнеры — под владельцем рабочей копии, иначе vendor/ и .cache/ станут root-овыми и git checkout/clean их не изменит
export UID
GID=$(id -g)
export GID

# composer — Alpine со свежим libxml: поведение XMLReader зависит от версии libxml
PHP_SERVICES=${PHP_SERVICES:-php81 php84 composer}
PHP_DEFAULT=${PHP_DEFAULT:-php84}

step() {
    echo
    echo "==> $1"
}

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
