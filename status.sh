#!/usr/bin/env bash
# say whether the app is running (exit code 0 = running, 3 = not running).
. "$(dirname "$(readlink -f "$0")")/scripts/common.sh"
if p=$(pid); then say "Running (pid $p) on http://$ADDR"; else say "Not running."; exit 3; fi
