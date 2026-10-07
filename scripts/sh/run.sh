#!/usr/bin/env bash
# команда приходит в base64, чтобы кавычки пережили две оболочки
. "$(dirname "$0")/lib.sh"
command=$(printf '%s' "${1:?usage: run.sh <base64 command> [service]}" | base64 -d)
service=${2:-composer}

ensure_vendor
compose_run "$service" sh -c "$command"
