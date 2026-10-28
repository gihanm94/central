#!/usr/bin/env bash
# git pull, build and start the app in the background (does nothing if it already runs). Add --no-pull to skip the pull.
. "$(dirname "$(readlink -f "$0")")/scripts/common.sh"
do_start "${1:-}"
