#!/usr/bin/env bash
set +x   # трассировка напечатала бы значения секретов
set -eEuo pipefail
unset CDPATH   # иначе «cd scripts/..» может уйти в одноимённый каталог чужого репозитория

tmp='' completed='' failed_line=''
on_exit() {
    local rc=$?
    if [ -n "$tmp" ]; then
        rm -rf -- "$tmp" 2>/dev/null || true
        if [ -e "$tmp" ]; then
            echo "ПРЕДУПРЕЖДЕНИЕ: не удалось удалить временный каталог $tmp (в нём содержимое индекса) — удалите вручную." >&2
        fi
    fi
    if [ -z "$completed" ]; then
        echo "ОШИБКА ПРОВЕРКИ: непредвиденный сбой${failed_line:+ в строке $failed_line} (код $rc)." >&2
        echo "Проверка не выполнена — коммит делать нельзя." >&2
        exit 2
    fi
}
trap on_exit EXIT
trap 'failed_line=$LINENO' ERR
finish() { completed=1; exit "$1"; }
fatal() {
    echo "ОШИБКА ПРОВЕРКИ: $1" >&2
    echo "Проверка не выполнена — коммит делать нельзя." >&2
    finish 2
}

usage() {
    cat <<'USAGE'
Проверка индекса git перед коммитом: в добавляемых строках и именах файлов нет секретов из .env и персональных
данных.

  bash scripts/check-staged.sh [--secrets-root DIR]
  CHECK_STAGED_ROOT=DIR bash scripts/check-staged.sh

Проверяется индекс репозитория (или worktree), в котором лежит скрипт; GIT_INDEX_FILE учитывается.
Секреты — значения GDESLON_* длиной от 6 символов из .env. Персональные данные — шаблоны (ERE, по строке) из
tests/_probe/pii-patterns.txt (файл в .gitignore: сами шаблоны тоже персональные). Ни значения, ни шаблоны
не печатаются.

Откуда брать .env и шаблоны: --secrets-root DIR, иначе CHECK_STAGED_ROOT — только этот каталог (относительный
путь — от текущего каталога, пустое значение — ошибка). Без них —
корень текущего checkout и, если это git worktree (там .env и tests/_probe/ обычно нет), основной checkout.

Код выхода: 0 — чисто; 1 — найдено; 2 — проверка не выполнена (git недоступен или упал, нет значений GDESLON_*
или шаблонов ПДн, невалидный шаблон, непредвиденный сбой). При 1 и 2 коммит делать нельзя.
Запускать в Git Bash: в PowerShell «bash» может оказаться WSL, где git не открывает worktree.
USAGE
}

secrets_root='' secrets_from=''
if [ -n "${CHECK_STAGED_ROOT+set}" ]; then secrets_root=$CHECK_STAGED_ROOT; secrets_from=CHECK_STAGED_ROOT; fi
while [ $# -gt 0 ]; do
    case $1 in
        --secrets-root)
            [ $# -ge 2 ] || fatal "--secrets-root: не указан каталог."
            secrets_root=$2; secrets_from=--secrets-root; shift 2 ;;
        --secrets-root=*) secrets_root=${1#*=}; secrets_from=--secrets-root; shift ;;
        -h|--help) usage; finish 0 ;;
        *) fatal "неизвестный аргумент: $1 (см. --help)." ;;
    esac
done

if [ -n "$secrets_from" ]; then
    [ -n "$secrets_root" ] || fatal "$secrets_from: пустой каталог источников."
    secrets_root=${secrets_root//\\//}
    [ -d "$secrets_root" ] || fatal "каталог источников «$secrets_root» не найден ($secrets_from)."
    secrets_root=$(cd "$secrets_root" && pwd)
fi

case ${BASH_SOURCE[0]} in */*) script_dir=${BASH_SOURCE[0]%/*} ;; *) script_dir=. ;; esac
cd "$script_dir/.." || fatal "не удалось перейти в каталог скрипта."
rc=0; top=$(git rev-parse --show-toplevel) || rc=$?
if [ "$rc" -ne 0 ] || [ -z "$top" ]; then
    fatal "$(pwd) — не git-репозиторий или git недоступен (код $rc). Запускайте в Git Bash: в PowerShell «bash» может оказаться WSL."
fi
cd "$top"

tmp=$(mktemp -d)
rc=0
git -c core.quotePath=false diff --cached --no-color --no-ext-diff --no-textconv --text \
    --src-prefix=a/ --dst-prefix=b/ -U0 >"$tmp/diff" || rc=$?
[ "$rc" -eq 0 ] || fatal "git diff --cached не выполнился (код $rc)."
rc=0; git -c core.quotePath=false diff --cached --name-only --no-renames --diff-filter=d >"$tmp/paths" || rc=$?
[ "$rc" -eq 0 ] || fatal "git diff --cached --name-only не выполнился (код $rc)."
rc=0; sed -n '/^+/{s/^+//;s/\r$//;p}' "$tmp/diff" >"$tmp/added" || rc=$?   # \r мешал бы якорю $
[ "$rc" -eq 0 ] || fatal "не удалось отобрать добавленные строки (sed, код $rc)."
added=$(grep -a -c -v -E '^\+\+ (b/|/dev/null)' "$tmp/added" || true)

main_checkout() {
    local line path=''
    while IFS= read -r line; do
        case $line in
            'worktree '*) path=${line#worktree } ;;
            bare) path='' ;;
            '') break ;;
        esac
    done < <(git worktree list --porcelain 2>/dev/null)
    if [ -n "$path" ] && [ -d "$path" ] && [ "$(cd "$path" && pwd -P)" != "$(pwd -P)" ]; then
        printf '%s\n' "$path"
    fi
}

if [ -n "$secrets_from" ]; then
    roots=("$secrets_root")
else
    roots=("$top")
    main=$(main_checkout)
    if [ -n "$main" ]; then roots+=("$main"); fi
fi

secret_names=() secret_values=() short_names=() pattern_values=() pattern_labels=() sources=() problems=()

contains() {
    local needle=$1 item
    shift
    for item in "$@"; do [ "$item" = "$needle" ] && return 0; done
    return 1
}

env_value() {
    local v=$1
    v=${v#"${v%%[![:space:]]*}"}
    case $v in
        \"*\"*) v=${v#\"}; v=${v%%\"*} ;;
        \'*\'*) v=${v#\'}; v=${v%%\'*} ;;
        *) v=${v%%[[:space:]]#*}; v=${v%"${v##*[![:space:]]}"} ;;
    esac
    REPLY=$v
}

read_env() {
    local file=$1 line name no=0 count=0
    if [ ! -f "$file" ]; then sources+=("  $file — нет файла"); return 0; fi
    if [ ! -r "$file" ]; then problems+=("$file не читается."); return 0; fi
    while IFS= read -r line || [ -n "$line" ]; do
        no=$((no + 1))
        if [ "$no" -eq 1 ]; then line=${line#$'\xEF\xBB\xBF'}; fi
        line=${line%$'\r'}
        [[ $line =~ ^[[:space:]]*(export[[:space:]]+)?(GDESLON_[A-Za-z0-9_]+)[[:space:]]*=(.*)$ ]] || continue
        name=${BASH_REMATCH[2]}
        env_value "${BASH_REMATCH[3]}"
        if [ ${#REPLY} -ge 6 ]; then
            count=$((count + 1))
            if ! contains "$REPLY" "${secret_values[@]}"; then secret_names+=("$name"); secret_values+=("$REPLY"); fi
        elif ! contains "$name" "${short_names[@]}"; then
            short_names+=("$name")
        fi
    done <"$file"
    sources+=("  $file — значений GDESLON_*: $count")
}

bad_escape='\\[ACDEFGHIJKLMNOPQRTUVXYZacdefghijklmnopqrtuvxyz0]'
read_patterns() {
    local file=$1 line no=0 count=0 rc err unescaped
    if [ ! -f "$file" ]; then sources+=("  $file — нет файла"); return 0; fi
    if [ ! -r "$file" ]; then problems+=("$file не читается."); return 0; fi
    while IFS= read -r line || [ -n "$line" ]; do
        no=$((no + 1))
        if [ "$no" -eq 1 ]; then line=${line#$'\xEF\xBB\xBF'}; fi
        line=${line%$'\r'}
        case $line in ''|\#*) continue ;; esac
        # Шаблон передаётся через -f, а не в аргументах: так он не виден в списке процессов.
        rc=0; err=$(grep -E -f <(printf '%s\n' "$line") </dev/null 2>&1 >/dev/null) || rc=$?
        unescaped=${line//\\\\/}
        if [ "$rc" -gt 1 ] || [ -n "$err" ] || [[ $unescaped =~ $bad_escape ]]; then
            problems+=("шаблон №$no ($file) — невалидный regex (или экранирование вроде \\d, которого нет в ERE).")
            continue
        fi
        count=$((count + 1))
        pattern_values+=("$line"); pattern_labels+=("№$no ($file)")
    done <"$file"
    sources+=("  $file — шаблонов ПДн: $count")
}

for root in "${roots[@]}"; do read_env "$root/.env"; done
for root in "${roots[@]}"; do read_patterns "$root/tests/_probe/pii-patterns.txt"; done
if [ ${#secret_values[@]} -eq 0 ]; then problems+=("нет значений GDESLON_* длиной от 6 символов (.env)."); fi
if [ ${#pattern_values[@]} -eq 0 ]; then problems+=("нет шаблонов ПДн (tests/_probe/pii-patterns.txt)."); fi

found=0
search() {
    local rc=0
    grep -q -a "$1" -f <(printf '%s\n' "$2") "$tmp/added" "$tmp/paths" 2>/dev/null || rc=$?
    case $rc in
        0) echo "$3 в индексе" >&2; found=1 ;;
        1) ;;
        *) problems+=("поиск не выполнился: $3 (grep, код $rc).") ;;
    esac
}
for i in "${!secret_values[@]}"; do search -F "${secret_values[$i]}" "СЕКРЕТ: значение ${secret_names[$i]}"; done
for i in "${!pattern_values[@]}"; do search -E "${pattern_values[$i]}" "ПЕРСОНАЛЬНЫЕ ДАННЫЕ: шаблон ${pattern_labels[$i]}"; done

print_sources() {
    local name unchecked=''
    echo "Источники:"
    printf '%s\n' "${sources[@]}"
    for name in "${short_names[@]}"; do
        if ! contains "$name" "${secret_names[@]}"; then unchecked+="${unchecked:+, }$name"; fi
    done
    if [ -n "$unchecked" ]; then echo "Не проверяются (короче 6 символов): $unchecked"; fi
}

for problem in "${problems[@]}"; do echo "ОШИБКА ПРОВЕРКИ: $problem" >&2; done
if [ "$found" -ne 0 ]; then
    echo "Коммит остановлен: уберите найденное из индекса." >&2
    finish 1
fi
if [ ${#problems[@]} -gt 0 ]; then
    print_sources >&2
    echo "Каталог с .env и tests/_probe/ можно указать явно: CHECK_STAGED_ROOT=DIR или --secrets-root DIR." >&2
    echo "Проверка не выполнена — коммит делать нельзя." >&2
    finish 2
fi
if [ "$added" -eq 0 ]; then
    echo "Индекс чист: добавленных строк нет."
else
    echo "Индекс чист: секретов и персональных данных не найдено (добавленных строк: $added)."
fi
print_sources
finish 0
