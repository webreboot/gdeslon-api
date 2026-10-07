#!/usr/bin/env bash
# Произвольная команда в контейнере composer (make remote-run CMD="..."). Команда приходит из scripts/remote.ps1
# в base64, чтобы кавычки пережили две оболочки. Использование: run.sh <base64 команда> [сервис]
. "$(dirname "$0")/lib.sh"
command=$(printf '%s' "${1:?usage: run.sh <base64 command> [service]}" | base64 -d)
service=${2:-composer}

ensure_vendor
compose_run "$service" sh -c "$command"
