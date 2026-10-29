# Deploying the company system on a Linux server

Written for Ubuntu / Debian. Two ways to get the program onto the server: **automatic** (GitHub Action, every push to `main`)
or **by hand** (`./start.sh` on the server). Do the one-time setup first.

## 1. One-time server setup

```bash
# packages
sudo apt update && sudo apt install -y postgresql nginx rsync git golang-go   # Go 1.24 or newer; or install from go.dev

# the user and folder the program runs from
sudo useradd --system --create-home --shell /bin/bash acme
sudo mkdir -p /srv/acme && sudo chown acme:acme /srv/acme

# databases (the installer creates the tables; it needs a login that may create databases)
sudo -u postgres psql -c "CREATE ROLE acme LOGIN CREATEDB PASSWORD 'choose-a-strong-password';"
```

Install the systemd unit from the README (**Deploying → systemd**), then `sudo systemctl daemon-reload && sudo systemctl enable acme`.
Let the deploy user restart it without a password (use the user the Action logs in as, here `acme`):

```bash
echo 'acme ALL=(root) NOPASSWD: /bin/systemctl restart acme, /bin/systemctl is-active acme' | sudo tee /etc/sudoers.d/acme
```

Add the nginx site from the README (**nginx (reverse proxy)**), then HTTPS:

```bash
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d company.example.com
```

## 2a. Automatic deploy (GitHub Action)

1. On the server, make an SSH key for the deploy user: `ssh-keygen -t ed25519 -f deploy_key -N ""`, append `deploy_key.pub` to
   `/home/acme/.ssh/authorized_keys`.
2. In GitHub → Settings → Secrets and variables → Actions add `SERVER_HOST`, `SERVER_USERNAME` (`acme`), `SERVER_SSH`
   (the whole private key) and optionally `SERVER_PORT`.
3. Push to `main` (or Actions → **Deploy** → Run workflow). It builds the program, copies it with `web/`, `lang/`, `config/`,
   `database/`, `public/`, `resources/` to `/srv/acme` and restarts the service. `config/config.json`, `storage/` and
   `public/uploads/` are never touched.

## 2b. Deploy by hand

```bash
sudo -u acme -i
git clone https://github.com/gihanm94/central.git ~/central && cd ~/central
GOTOOLCHAIN=local go build -o acme ./cmd/acme
rsync -a --delete --exclude config/config.json --exclude storage --exclude public/uploads \
      acme web lang config database public resources /srv/acme/
sudo systemctl restart acme
```

Later updates: `git pull`, the same build and rsync, restart. (`./start.sh` / `./restart.sh` are for running from the checkout, e.g. on a laptop.)

## 3. First start

Open `https://company.example.com` — you are sent to the installer. Enter the database login from step 1 and the admin account,
click **Install now**. Then set `app.url` in `/srv/acme/config/config.json` to the same address and `sudo systemctl restart acme`.

## 4. Check it works

```bash
sudo systemctl status acme            # active (running)
curl -I http://127.0.0.1:8000/login   # 200
journalctl -u acme -f                 # live log; the error log is /srv/acme/storage/logs/error.log
```

## 5. Backups and scheduled jobs

```bash
# nightly database dump (crontab -e as postgres)
0 2 * * * for d in user_db crm_db account_db inventory_db machine_db hr_db; do pg_dump $d | gzip > /var/backups/$d-$(date +\%F).sql.gz; done
# reminders every 5 minutes (crontab -e as acme)
*/5 * * * * /srv/acme/acme reminders
```

Back up `/srv/acme/config/config.json`, `/srv/acme/storage/` and `/srv/acme/public/uploads/` too.

## Troubleshooting

| Problem | Check |
|---|---|
| Deploy says nothing was deployed | the three secrets are not all set |
| 502 from nginx | `systemctl status acme`; the program listens on 127.0.0.1:8000 |
| Redirects to `http://` or login loops | nginx must send `X-Forwarded-Proto`; `app.url` must be the https address |
| Database login lost | `/install` → **Reconnect the database** (on the server itself only) |
