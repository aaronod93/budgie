# Deploying Lil' Budgie — co-hosted with StaceLib on the same VPS

Lil' Budgie runs from `docker-compose.prod.yml` on the same server as StaceLib.
**It publishes no ports of its own**: StaceLib's Caddy already owns 80/443 and
routes by hostname, so Budgie's containers simply join a shared docker network
(`edge`) and Caddy gains two more site blocks. One proxy, one certificate
manager, no port conflicts.

```
                          +---------------------------- VPS ---------------------------+
  library.example.com --> |                                                            |
  api.library.example --> |  Caddy (stacelib stack, :80/:443) --> stacelib containers  |
                          |       |                                                    |
  lilbudgie.com       --> |       +- edge network -> budgie-web:3000                   |
  api.lilbudgie.com   --> |       +- edge network -> budgie-api:8080                   |
  api.lilbudgie.com/app/* |       +- edge network -> budgie-reverb:8080 (websockets)   |
                          +------------------------------------------------------------+
```

Prerequisite: StaceLib deployed and working per its own `docs/DEPLOY.md`
(server hardening, Docker, Cloudflare token — all reused, not repeated here).

## One-time setup

1. **DNS (Cloudflare)** — two more proxied A records at the same server IP:
   - `lilbudgie.com` (web app)
   - `api.lilbudgie.com` (API + websockets)

   The API domain **must** be a subdomain of the app domain: the Sanctum
   session cookie is scoped to `.lilbudgie.com` and has to reach both.
   `lilbudgie.com` is its own Cloudflare zone, so the DNS-01 certificate
   token must cover it: either widen the existing `CLOUDFLARE_API_TOKEN`
   to include the lilbudgie.com zone, or create a fresh token scoped to
   both zones and update StaceLib's `.env`.

2. **Shared network** (once per server):
   ```bash
   docker network create edge
   ```

3. **Update StaceLib** — its repo now carries the Budgie routing
   (Caddyfile blocks + `edge` network + two env vars):
   ```bash
   cd /var/www/stacelib
   git pull
   nano .env      # add: BUDGIE_APP_DOMAIN=lilbudgie.com
                  #      BUDGIE_API_DOMAIN=api.lilbudgie.com
   docker compose -f docker-compose.prod.yml up -d   # recreates caddy
   ```
   Caddy will immediately try to obtain certificates for the new domains —
   `docker compose -f docker-compose.prod.yml logs -f caddy` should show
   `certificate obtained successfully` for both.

4. **Clone Budgie**:
   ```bash
   cd /var/www
   git clone https://github.com/<you>/budgie.git budgie
   cd budgie
   git config credential.helper store
   ```

5. **Configure**:
   ```bash
   cp .env.production.example .env
   nano .env
   ```
   Fill in: the two domains, `APP_KEY` (`php artisan key:generate --show`
   locally), a long `DB_PASSWORD`, random `REVERB_APP_KEY`/`REVERB_APP_SECRET`
   (any long random strings), and SMTP credentials for invitation/reset emails.

6. **Launch**:
   ```bash
   docker compose -f docker-compose.prod.yml up -d --build
   docker compose -f docker-compose.prod.yml logs -f api
   ```
   First boot builds the images and AUTORUN applies migrations. Then open
   `https://lilbudgie.com`, register, and create your budget. Register
   promptly — certificate issuance publishes the hostnames to CT logs, and
   scanners probe new sites within seconds.

7. **Smoke-test the websocket** — open the budget in two browser windows and
   add a transaction in one; the other should refresh and show a toast within
   a second. (Cloudflare proxies websockets without extra configuration.)

## Updating

```bash
cd /var/www/budgie
git pull
./scripts/deploy-prod.sh     # up -d --build + migrate
```

## Backups

Nightly database dump (add to `crontab -e`, alongside StaceLib's):

```cron
30 3 * * * cd /var/www/budgie && docker compose -f docker-compose.prod.yml exec -T mariadb sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" budgie' | gzip > /root/backups/budgie-$(date +\%F).sql.gz
```

Lil' Budgie stores no user files outside the database, so the SQL dump is the
whole backup. Keep ~14 days and sync `/root/backups` off-box.

## The mobile app against production

No build-time URL is baked in — the sign-in screen has a **Server URL** field.
Build a release APK and enter `https://api.lilbudgie.com` on first sign-in:

```bash
cd mobile
flutter build apk --release
```

Two hardening notes before distributing a release build:
- Remove `android:usesCleartextTraffic="true"` from
  `mobile/android/app/src/main/AndroidManifest.xml` (dev-only convenience —
  production is HTTPS).
- Set up a release signing key (see StaceLib's DEPLOY.md § *Release signing
  key* — the same procedure applies; keep the keystore out of the repo).

## Notes

- **Container names**: on the `edge` network Budgie's services use the
  aliases `budgie-web`, `budgie-api`, `budgie-reverb`, so they can never
  collide with StaceLib's `web`/`api` service names.
- **Websockets** ride the API domain: browsers connect to
  `wss://api.lilbudgie.com/app/{key}` (Caddy routes `/app/*` to the
  reverb container); the API publishes to `reverb:8080` container-to-container.
- **Queues**: the `worker` container handles queued jobs (redis driver); the
  `scheduler` container posts due scheduled transactions daily at 00:05.
- **StaceLib decoupling**: Budgie only depends on StaceLib's *Caddy*. If
  StaceLib is ever retired, move the Caddyfile (and the caddy service) into
  the Budgie stack and drop the `BUDGIE_*` prefixes.
