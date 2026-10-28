#!/usr/bin/env bash
# say whether the app is running (exit code 0 = running, 3 = not running).
set -euo pipefail
DIR=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)
cd "$DIR"
[ -f acme.env ] && set -a && . ./acme.env && set +a      # optional: ACME_ADDR, ACME_BRANCH
ADDR=${ACME_ADDR:-127.0.0.1:8000}
BRANCH=${ACME_BRANCH:-acme-go}
BIN=$DIR/acme
PIDFILE=$DIR/storage/acme.pid
LOG=$DIR/storage/logs/acme.log
say() { printf '%s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

pid() {
  [ -f "$PIDFILE" ] || return 1
  local p; p=$(cat "$PIDFILE" 2>/dev/null) || return 1
  [ -n "$p" ] && kill -0 "$p" 2>/dev/null || return 1
  tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null | grep -q "$BIN" || return 1
  printf '%s' "$p"
}

if p=$(pid); then say "Running (pid $p) on http://$ADDR"; else say "Not running."; exit 3; fi
