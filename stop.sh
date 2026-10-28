#!/usr/bin/env bash
# stop the app (waits for it to finish, forces it after 10 s).
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

do_stop() {
  local p
  if ! p=$(pid); then rm -f "$PIDFILE"; say "Not running."; return 0; fi
  kill "$p"
  for _ in $(seq 1 50); do kill -0 "$p" 2>/dev/null || break; sleep 0.2; done
  if kill -0 "$p" 2>/dev/null; then say "Still running after 10 s: forcing it."; kill -9 "$p" || true; fi
  rm -f "$PIDFILE"; say "Stopped."
}

do_stop
