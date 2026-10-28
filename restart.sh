#!/usr/bin/env bash
# stop the app, git pull, build and start it again. Add --no-pull to skip the pull.
. "$(dirname "$(readlink -f "$0")")/scripts/common.sh"
do_stop; do_start "${1:-}"
