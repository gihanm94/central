# Testing a ported area

Shared services (already running; do not stop them):
- PostgreSQL: socket /tmp, port 5432, user postgres. Databases user_db, crm_db, account_db, machine_db, inventory_db, hr_db.
- PHP reference app: http://localhost:8000 (code in /var/tmp/app). Use **localhost**, not 127.0.0.1, for PHP (cookies).
- Accounts: admin / Secret123A; management, member, bu.manager2 / Password@123.

Your own Go server (pick the port you were given):

    BASE=/var/tmp/go-<area>; PORT=81xx
    mkdir -p $BASE/storage/cache $BASE/storage/logs
    rsync -a --delete --exclude .git --exclude storage --exclude config/config.json <your worktree>/ $BASE/
    cp /var/tmp/goapp/config/config.json $BASE/config/ && cp /var/tmp/goapp/storage/installed.lock $BASE/storage/
    sed -i "s#http://127.0.0.1:8001#http://127.0.0.1:$PORT#" $BASE/config/config.json
    GOTOOLCHAIN=local go build -o $BASE/acme ./cmd/acme && ($BASE/acme -addr 127.0.0.1:$PORT -base $BASE > $BASE/run.log 2>&1 &)
    # stop it with: kill $(pgrep -f "acme -addr 127.0.0.1:$PORT" | head -1)   (never pkill -f with a pattern your own shell matches)

Errors land in $BASE/storage/logs/php-error.log (with stack traces) and $BASE/run.log.

Screenshots side by side (PHP left, Go right):

    NODE_PATH=/tmp/claude-0/-home-user/8b70e043-48e7-5f6a-8a6d-9b90de4b501e/scratchpad/node_modules GO=http://127.0.0.1:$PORT OUT=/tmp/shots-<area> node tools/shot.js /crm/leads /crm/leads/1
    python3 tools/shotcmp.py /tmp/shots-<area> _crm_leads _crm_leads_1      # then look at cmp_crm_leads.png

Also exercise forms (create / edit / delete), exports and JSON endpoints with Playwright or curl, and compare
database rows written by both versions. Remove test rows you created.
