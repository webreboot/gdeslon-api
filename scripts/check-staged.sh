#!/usr/bin/env bash
# Проверка индекса git перед коммитом: в добавляемых изменениях нет секретов из .env и персональных данных.
#
# Секреты — значения GDESLON_* из .env (не печатаются). Персональные данные — шаблоны (regex, по строке) из
# tests/_probe/pii-patterns.txt (файл в .gitignore: сами шаблоны тоже персональные).
# Код выхода 1 — найдено (коммит делать нельзя), 0 — чисто.
set -euo pipefail
cd "$(dirname "$0")/.."

diff=$(git diff --cached -U0 | grep -E '^\+' || true)
found=0

if [ -f .env ]; then
    while IFS='=' read -r name value; do
        value=${value%$'\r'}
        value=${value#\"}; value=${value%\"}
        if [ -n "$value" ] && [ ${#value} -ge 6 ] && grep -qF -- "$value" <<<"$diff"; then
            echo "СЕКРЕТ: значение $name в индексе" >&2
            found=1
        fi
    done < <(grep -E '^GDESLON_[A-Z_]+=' .env || true)
fi

if [ -f tests/_probe/pii-patterns.txt ]; then
    while IFS= read -r pattern; do
        pattern=${pattern%$'\r'}
        [ -z "$pattern" ] && continue
        case "$pattern" in \#*) continue ;; esac
        if grep -qE -- "$pattern" <<<"$diff"; then
            echo "ПЕРСОНАЛЬНЫЕ ДАННЫЕ: шаблон №$(grep -nxF -- "$pattern" tests/_probe/pii-patterns.txt | cut -d: -f1) в индексе" >&2
            found=1
        fi
    done < tests/_probe/pii-patterns.txt
fi

if [ "$found" -ne 0 ]; then
    echo "Коммит остановлен: уберите найденное из индекса." >&2
    exit 1
fi
echo "Индекс чист: секретов и персональных данных не найдено."
