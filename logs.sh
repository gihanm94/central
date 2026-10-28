#!/usr/bin/env bash
# follow the app's output (Ctrl+C to leave).
. "$(dirname "$(readlink -f "$0")")/scripts/common.sh"
mkdir -p "$(dirname "$LOG")"; touch "$LOG"
exec tail -n 50 -f "$LOG"
