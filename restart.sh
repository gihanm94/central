#!/usr/bin/env bash
# stop the app, git pull, build and start it again. Add --no-pull to skip the pull.
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

update() {
  if [ "${1:-}" != "--no-pull" ] && [ -d .git ]; then
    say "Pulling $BRANCH ..."
    git fetch -q origin "$BRANCH"
    git merge -q --ff-only "origin/$BRANCH" || die "cannot fast-forward: this folder has local changes or its own commits. Fix that, or use --no-pull."
  fi
  command -v go >/dev/null || die "Go is not installed (needs 1.24 or newer): https://go.dev/dl/"
  say "Building ..."
  go build -o "$BIN.new" ./cmd/acme
  mv -f "$BIN.new" "$BIN"          # replaced in one step, so a running copy is never half-written
}

do_start() {
  local p
  if p=$(pid); then say "Already running (pid $p) on $ADDR. Use ./restart.sh to restart it."; return 0; fi
  update "${1:-}"
  mkdir -p "$DIR/storage/logs"
  [ -f config/config.json ] || say "Note: config/config.json is missing; open http://$ADDR/install to set the app up."
  nohup "$BIN" -addr "$ADDR" -base "$DIR" >> "$LOG" 2>&1 &
  echo $! > "$PIDFILE"
  sleep 1
  if p=$(pid); then say "Started (pid $p) on http://$ADDR  — log: storage/logs/acme.log"
  else rm -f "$PIDFILE"; tail -n 15 "$LOG" >&2; die "it did not start (see the log above)"; fi
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
do_start "${1:-}"
