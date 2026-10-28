#!/usr/bin/env bash
# stop the app (waits for it to finish, forces it after 10 s).
. "$(dirname "$(readlink -f "$0")")/scripts/common.sh"
do_stop
