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

# the process id of the running app: from the pid file, else whatever listens on our port (works on Linux and macOS)
pid() {
  local p="" port="${ADDR##*:}" q
  if [ -f "$PIDFILE" ]; then p=$(cat "$PIDFILE" 2>/dev/null || true); fi
  if [ -z "$p" ] || ! kill -0 "$p" 2>/dev/null || ! ps -p "$p" -o command= 2>/dev/null | grep -q "$BIN"; then
    p=""
    if command -v lsof >/dev/null 2>&1; then
      for q in $(lsof -ti "tcp:$port" -sTCP:LISTEN 2>/dev/null || true); do
        if ps -p "$q" -o command= 2>/dev/null | grep -q "$BIN"; then p=$q; break; fi
      done
    fi
  fi
  [ -n "$p" ] || return 1
  printf '%s' "$p"
}

if p=$(pid); then say "Running (pid $p) on http://$ADDR"; else say "Not running."; exit 3; fi
