#!/usr/bin/env bash
set -uo pipefail

sut="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-staged.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

export HOME="$work/home" GIT_CONFIG_NOSYSTEM=1 GIT_CEILING_DIRECTORIES="$work"
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_CONFIG_COUNT \
    GIT_CONFIG_GLOBAL GIT_CONFIG_PARAMETERS XDG_CONFIG_HOME CHECK_STAGED_ROOT
mkdir -p "$HOME"

SECRET=synthTOKEN123456
ENV_OK=$'GDESLON_API_TOKEN=synthTOKEN123456\nGDESLON_USER_ID=4321x\nREMOTE=example\n'
PATTERNS_OK=$'# комментарий\nИванов\nsynthPII[0-9]{3}\n'
LEAKS=("$SECRET" '4321x' 'synthOTHER987' 'a.b*c=+x' '-synthDASH1' 'synthPII[' 'Иванов' '(unclosed')

passed=0 failed=0 n=0 out='' code=0

put() { mkdir -p "$(dirname "$1")"; printf '%s' "$2" >"$1"; }
stage() { put "$1/$2" "$3"; git -C "$1" add -- "$2"; }
commit() { git -C "$1" -c user.name=test -c user.email=test@example.invalid commit -qm "$2"; }

new_repo() {
    n=$((n + 1)); r="$work/r$n"
    mkdir -p "$r/scripts"
    git init -q "$r"
    cp "$sut" "$r/scripts/check-staged.sh"
    put "$r/README" $'base\n'
    git -C "$r" add README scripts
    commit "$r" init
}

sources() {
    [ "$2" = - ] || put "$1/.env" "$2"
    [ "$3" = - ] || put "$1/tests/_probe/pii-patterns.txt" "$3"
}

run() { local dir=$1; shift; out=$(cd "$dir" && "$@" 2>&1); code=$?; }

expect() {
    local name=$1 want=$2 frag leak problem=''
    shift 2
    [ "$code" -eq "$want" ] || problem+="; код $code, ожидался $want"
    if [ "$want" -ne 0 ] && grep -qF 'Индекс чист' <<<"$out"; then problem+="; «Индекс чист» при коде $want"; fi
    for frag in "$@"; do
        case $frag in
            +*) grep -qF -- "${frag#+}" <<<"$out" || problem+="; нет «${frag#+}»" ;;
            -*) ! grep -qF -- "${frag#-}" <<<"$out" || problem+="; есть «${frag#-}»" ;;
        esac
    done
    for leak in "${LEAKS[@]}"; do
        if grep -qF -- "$leak" <<<"$out"; then problem+="; в выводе значение или шаблон"; break; fi
    done
    if [ -z "$problem" ]; then
        passed=$((passed + 1)); echo "ok   $name"
    else
        failed=$((failed + 1)); echo "FAIL $name: ${problem#; }"; sed 's/^/     | /' <<<"$out"
    fi
}

check() { run "$r" bash scripts/check-staged.sh "$@"; }

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"
check
expect 'индекс без изменений' 0 '+добавленных строк нет' '+значений GDESLON_*: 1' '+шаблонов ПДн: 2'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt $'neutral\n'
check
expect 'нейтральная строка' 0 '+Индекс чист' '+добавленных строк: 1' '+Не проверяются (короче 6 символов): GDESLON_USER_ID'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt "token=$SECRET"$'\n'
check
expect 'секрет в индексе' 1 '+СЕКРЕТ: значение GDESLON_API_TOKEN в индексе' '+Коммит остановлен'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt $'x synthPII123\n'
check
expect 'шаблон ПДн' 1 '+ПЕРСОНАЛЬНЫЕ ДАННЫЕ: шаблон №3'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt $'автор: Иванов\n'
check
expect 'кириллический шаблон' 1 '+шаблон №2'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt "$SECRET synthPII123"$'\n'
check
expect 'секрет и ПДн вместе' 1 '+СЕКРЕТ: значение GDESLON_API_TOKEN' '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt "$SECRET"$'\n'; commit "$r" secret
git -C "$r" rm -q a.txt
check
expect 'секрет только в удалённой строке' 0 '+Индекс чист'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" synthPII123.txt $'neutral\n'
check
expect 'шаблон в имени файла' 1 '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; printf 'a\0%s\0b' "$SECRET" >"$r/bin.dat"; git -C "$r" add bin.dat
check
expect 'секрет в бинарном файле' 1 '+СЕКРЕТ: значение GDESLON_API_TOKEN'

n=$((n + 1)); d="$work/outside$n"; mkdir -p "$d/scripts"; cp "$sut" "$d/scripts/"; sources "$d" "$ENV_OK" "$PATTERNS_OK"
run "$d" bash scripts/check-staged.sh
expect 'копия скрипта вне репозитория' 2 '+не git-репозиторий'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"
run "$r" env GIT_DIR="$work/nonexistent" bash scripts/check-staged.sh
expect 'битый GIT_DIR' 2 '+не git-репозиторий'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; mkdir -p "$work/empty-path"
run "$r" env PATH="$work/empty-path" "$BASH" scripts/check-staged.sh
expect 'нет git и утилит в PATH' 2 '+ОШИБКА ПРОВЕРКИ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; git -C "$r" config color.ui always; git -C "$r" config color.diff always
stage "$r" a.txt "$SECRET"$'\n'
check
expect 'color.ui=always' 1 '+СЕКРЕТ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; git -C "$r" config diff.external true; stage "$r" a.txt "$SECRET"$'\n'
check
expect 'diff.external' 1 '+СЕКРЕТ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; git -C "$r" config diff.hide.textconv true
stage "$r" .gitattributes $'a.txt diff=hide\n'; stage "$r" a.txt "$SECRET"$'\n'
check
expect 'textconv' 1 '+СЕКРЕТ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; git -C "$r" config diff.noprefix true; stage "$r" synthPII123.txt $'n\n'
check
expect 'diff.noprefix' 1 '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; mkdir -p "$work/empty-root"; stage "$r" a.txt "$SECRET"$'\n'
run "$r" env CHECK_STAGED_ROOT="$work/empty-root" bash scripts/check-staged.sh
expect 'явный корень пуст (строго он)' 2 '+нет значений GDESLON_*' '+нет шаблонов ПДн' '+нет файла'

new_repo; sources "$r" $'REMOTE=example\nREMOTE_DIR=/srv/x\n' "$PATTERNS_OK"
check
expect '.env только с REMOTE*' 2 '+нет значений GDESLON_*' '+CHECK_STAGED_ROOT'

new_repo; sources "$r" $'GDESLON_USER_ID=4321x\nGDESLON_API_KEY=\n' "$PATTERNS_OK"
check
expect 'только короткие GDESLON_*' 2 '+нет значений GDESLON_*' '+GDESLON_USER_ID'

new_repo; sources "$r" - "$PATTERNS_OK"
check
expect 'нет .env' 2 '+нет значений GDESLON_*' '+нет файла'

new_repo; sources "$r" "$ENV_OK" -
check
expect 'нет pii-patterns.txt' 2 '+нет шаблонов ПДн'

new_repo; sources "$r" "$ENV_OK" $'# только комментарий\n\n'
check
expect 'pii-patterns.txt без шаблонов' 2 '+нет шаблонов ПДн'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"
run "$r" env CHECK_STAGED_ROOT="$work/nonexistent" bash scripts/check-staged.sh
expect 'явный корень не существует' 2 '+не найден'

new_repo; sources "$r" "$ENV_OK" $'synthPII[0-9]{3}\n(unclosed\n'; stage "$r" a.txt $'neutral\n'
check
expect 'невалидный regex' 2 '+шаблон №2' '+невалидный regex' '-grep:'

new_repo; sources "$r" "$ENV_OK" $'synthPII[0-9]{3}\n(unclosed\n'; stage "$r" a.txt "$SECRET"$'\n'
check
expect 'секрет и невалидный regex' 1 '+СЕКРЕТ' '+невалидный regex' '+Коммит остановлен'

new_repo; sources "$r" "$ENV_OK" $'# c\nИванов\nsynthPII[0-9]{3}'; stage "$r" a.txt $'synthPII123\n'
check
expect 'последняя строка шаблонов без перевода строки' 1 '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" $'# c\r\nИванов\r\nsynthPII[0-9]{3}\r\n'; stage "$r" a.txt $'synthPII123\n'
check
expect 'шаблоны с CRLF' 1 '+шаблон №3 ('

new_repo; sources "$r" "$ENV_OK" $'synthPII[0-9]{3}\n# c\nsynthPII[0-9]{3}\n'; stage "$r" a.txt $'synthPII123\n'
check
expect 'повтор шаблона' 1 '+шаблон №1 (' '+шаблон №3 ('

new_repo; sources "$r" "$ENV_OK" $'\xEF\xBB\xBFsynthPII[0-9]{3}\n'; stage "$r" a.txt $'synthPII123\n'
check
expect 'шаблоны с BOM' 1 '+шаблон №1 ('

env_forms=(
    $'GDESLON_API_TOKEN=synthTOKEN123456\r\n'
    $'\xEF\xBB\xBFGDESLON_API_TOKEN=synthTOKEN123456\n'
    $'GDESLON_API_TOKEN="synthTOKEN123456"\n'
    $'GDESLON_API_TOKEN="synthTOKEN123456"  # comment\n'
    $'GDESLON_API_TOKEN=\'synthTOKEN123456\'\n'
    $'GDESLON_API_TOKEN=synthTOKEN123456 # comment\n'
    $'export GDESLON_API_TOKEN=synthTOKEN123456\n'
    $'  GDESLON_API_TOKEN=synthTOKEN123456\n'
    $'GDESLON_API_TOKEN = synthTOKEN123456\n'
    $'GDESLON_API_TOKEN2=synthTOKEN123456\n'
    $'GDESLON_API_TOKEN=synthTOKEN123456'
)
for i in "${!env_forms[@]}"; do
    new_repo; sources "$r" "${env_forms[$i]}" "$PATTERNS_OK"; stage "$r" a.txt "$SECRET"$'\n'
    check
    expect ".env, форма записи №$((i + 1))" 1 '+СЕКРЕТ: значение GDESLON_API_TOKEN'
done

new_repo; sources "$r" $'GDESLON_API_KEY=a.b*c=+x\n' "$PATTERNS_OK"; stage "$r" a.txt $'aXbbc=+x\n'
check
expect 'спецсимволы regex в значении: не regex' 0 '+Индекс чист'
stage "$r" b.txt $'a.b*c=+x\n'
check
expect 'спецсимволы regex в значении: буквально' 1 '+СЕКРЕТ: значение GDESLON_API_KEY'

new_repo; sources "$r" $'GDESLON_API_KEY=-synthDASH1\n' "$PATTERNS_OK"; stage "$r" a.txt $'-synthDASH1\n'
check
expect 'значение начинается с «-»' 1 '+СЕКРЕТ: значение GDESLON_API_KEY'

new_repo; root="$work/корень с пробелом"; sources "$root" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt "$SECRET"$'\n'
run "$r" env CHECK_STAGED_ROOT="$root" bash scripts/check-staged.sh
expect 'CHECK_STAGED_ROOT с пробелом и кириллицей' 1 '+СЕКРЕТ'
if command -v cygpath >/dev/null 2>&1; then
    run "$r" env CHECK_STAGED_ROOT="$(cygpath -w "$root")" bash scripts/check-staged.sh
    expect 'CHECK_STAGED_ROOT в виде пути Windows' 1 '+СЕКРЕТ'
fi

new_repo; a="$work/root-a"; b="$work/root-b"
sources "$a" "$ENV_OK" "$PATTERNS_OK"; sources "$b" $'GDESLON_API_KEY=synthOTHER987\n' "$PATTERNS_OK"
stage "$r" a.txt "$SECRET"$'\n'
run "$r" env CHECK_STAGED_ROOT="$a" bash scripts/check-staged.sh --secrets-root "$b"
expect '--secrets-root важнее CHECK_STAGED_ROOT' 0 "+$b/.env" "-$a/.env"
run "$r" bash scripts/check-staged.sh --secrets-root="$a"
expect '--secrets-root=DIR' 1 '+СЕКРЕТ'

new_repo; main=$r; sources "$main" "$ENV_OK" "$PATTERNS_OK"
git -C "$main" worktree add -q "$work/wt" 2>/dev/null; wt="$work/wt"; put "$wt/.env" $'REMOTE=example\n'
run "$wt" bash scripts/check-staged.sh
expect 'worktree: источники основного checkout' 0 '+добавленных строк нет' \
    "+/${main##*/}/.env — значений GDESLON_*: 1" "+/${main##*/}/tests/_probe/pii-patterns.txt — шаблонов ПДн: 2"
stage "$wt" a.txt "$SECRET"$'\n'
run "$wt" bash scripts/check-staged.sh
expect 'worktree: секрет в индексе' 1 '+СЕКРЕТ: значение GDESLON_API_TOKEN'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; mkdir -p "$work/empty-root"
run "$r" env CHECK_STAGED_ROOT="$work/empty-root" bash scripts/check-staged.sh --secrets-root ''
expect 'пустой --secrets-root' 2 '+--secrets-root'
run "$r" env CHECK_STAGED_ROOT= bash scripts/check-staged.sh
expect 'пустой CHECK_STAGED_ROOT' 2 '+CHECK_STAGED_ROOT'
run "$r/scripts" bash check-staged.sh --secrets-root .
expect 'относительный --secrets-root — от текущего каталога' 2 '+нет значений GDESLON_*'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" 'Иванов.txt' $'neutral\n'
check
expect 'шаблон в кириллическом имени файла' 1 '+шаблон №2'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt $'neutral\n'; commit "$r" a
git -C "$r" mv a.txt synthPII123.txt
check
expect 'шаблон в имени при переименовании без правок' 1 '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" synthPII123.txt ''
check
expect 'шаблон в имени пустого файла' 1 '+шаблон №3'

new_repo; sources "$r" "$ENV_OK" $'^synthPII[0-9]{3}\n'; stage "$r" a.txt $'synthPII123\n'
check
expect 'шаблон с якорем ^' 1 '+шаблон №1'

new_repo; sources "$r" "$ENV_OK" $'synthPII[0-9]{3}$\n'; stage "$r" a.txt $'synthPII123\r\n'
check
expect 'шаблон с якорем $ и строка с CRLF' 1 '+шаблон №1'

new_repo; sources "$r" "$ENV_OK" $'Иванов\nsynthPII\\d{3}\n'; stage "$r" a.txt $'synthPII123\n'
check
expect 'экранирование \d, которого нет в ERE' 2 '+шаблон №2' '+невалидный regex' '-synthPII\d'

new_repo; sources "$r" "$ENV_OK" $'synthPII\\w\\\\d[0-9]{2}\\.\n'; stage "$r" a.txt $'synthPIIx\\d12.\n'
check
expect 'допустимые экранирования \w, \\, \.' 1 '+шаблон №1'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"
run "$r" env TMPDIR="$work/nonexistent" bash scripts/check-staged.sh
expect 'непредвиденный сбой' 2 '+непредвиденный сбой'
mkdir -p "$work/fakebin" "$work/tmpdir"
printf '#!/bin/sh\n: >"%s"\nexit 1\n' "$work/rm-called" >"$work/fakebin/rm"; chmod +x "$work/fakebin/rm"
rm_fails() { rm -f "$work/rm-called"; run "$r" env PATH="$work/fakebin:$PATH" TMPDIR="$work/tmpdir" bash scripts/check-staged.sh; }
rm_called() { if [ -e "$work/rm-called" ]; then echo '+не удалось удалить временный каталог'; else echo '+(фальшивый rm не вызван)'; fi; }
rm_fails
expect 'сбой rm при уборке: код 0 и предупреждение' 0 '+Индекс чист' "$(rm_called)"
stage "$r" a.txt "$SECRET"$'\n'
rm_fails
expect 'сбой rm при уборке: код 1 и предупреждение' 1 '+СЕКРЕТ' "$(rm_called)"
git -C "$r" rm -q --cached a.txt; rm -f "$r/.env"
rm_fails
expect 'сбой rm при уборке: код 2 и предупреждение' 2 '+нет значений GDESLON_*' "$(rm_called)"

new_repo; a=$r; sources "$a" "$ENV_OK" "$PATTERNS_OK"; stage "$a" a.txt "$SECRET"$'\n'
new_repo; sources "$r" $'GDESLON_API_KEY=synthOTHER987\n' "$PATTERNS_OK"
run "$a" env CDPATH="$r" bash scripts/check-staged.sh
expect 'CDPATH не подменяет проверяемый репозиторий' 1 '+СЕКРЕТ'
run "$a" env CDPATH="$r" bash scripts/check-staged.sh --secrets-root scripts/..
expect 'CDPATH не подменяет --secrets-root' 1 '+СЕКРЕТ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"; stage "$r" a.txt "$SECRET"$'\n'
run "$r/scripts" bash check-staged.sh
expect 'запуск из подкаталога' 1 '+СЕКРЕТ'
run "$r" bash -x scripts/check-staged.sh
expect 'bash -x не печатает значения' 1 '+СЕКРЕТ'

new_repo; sources "$r" "$ENV_OK" "$PATTERNS_OK"
check --bogus
expect 'неизвестный аргумент' 2 '+неизвестный аргумент'
check --help
expect '--help' 0 '+CHECK_STAGED_ROOT' '+--secrets-root'

echo "Итого: пройдено $passed, упало $failed."
[ "$failed" -eq 0 ]
