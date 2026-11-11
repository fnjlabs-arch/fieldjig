# SadSha Build on Contabo VPS 8 (shared with Massing) — deployment guide

**Status (2026-11-11, hosting prep):** this is the deployment guide for the SadSha
Build hosted service — Fieldjig as a multi-tenant SaaS — on the ONE Contabo VPS 8
shared with Massing (SadSha Build Pro). It is written against the repo as it stands
on branch `remix/fieldjig-hosting`. The Docker full stack has **not** been run
anywhere yet: Docker is not installed on the authoring VM, so what is validated
here is the static side — the compose file parses, the env template covers every
variable the compose files and this guide name, and every command below matches
the repo's actual file layout (paths, service names, ports). The first live `up`
on the VPS is still owed, and §12 lists exactly what it has to prove.

## 0. The decisions this guide is built on

| Decision | Value | Source |
| --- | --- | --- |
| Product | SadSha Build — construction management for small builders, multi-tenant SaaS | Remixer design §5 / clean report §1 |
| Price | **$99/mo flat** (Pro); 30-day free trial on one live project, paid from month 2 | Mili, on Qasim's "As you wish" delegation — open to his override |
| Server | Contabo **Cloud VPS 8 — €14/mo (~$16)**, **shared with Massing** | Qasim, decided |
| Server budget | **$25/mo** (both apps together: €14 ≈ $16, inside budget) | Clean report §8 |
| Margin | **~$83 per Fieldjig customer per month** ($99 − ~$16 server) **before support and before Massing's share of the box** | Arithmetic, not a promise — support time is the real cost |
| Billing | Lemon Squeezy (temporary gateway; Paddle is stuck on proof-of-address) | Remixer design §2 |
| Shape | One VPS, Docker Compose, Caddy for TLS — no Kubernetes, no managed database | This guide, mirroring Massing's guide |

One shared box instead of two: €14/mo for both apps, versus €14 + €5.50 = €19.50
for a VPS 8 plus a VPS 4. At one paying Fieldjig customer the shared server is
already covered about six times over; at one customer of each app it is covered
about eighteen times over. That is the whole economic argument for sharing — do
not "fix" it by buying a second box early. The cost that grows is support, not
hosting.

**Billing blocker — read before promising any date:** the Lemon Squeezy "Toolzo"
store is still **"In Review" (Test mode ON)**. Live billing cannot activate until
it is approved, and no Lemon Squeezy products or variants may be created until
then. The environment variables for billing are already plumbed through the
compose stack and the env template (§4), but the code that reads them does not
exist yet — tenancy, billing, signup and the onboarding permission model are
separate Remixer work. A deployment made from this guide today is a working
single-tenant Fieldjig with auth and RBAC; it is not yet a store that can take a
subscription.

**Companion doc — read it first if you have not:** Massing's
`docs/saas/contabo-vps8.md` (SadSha Build Pro on the same box). The two guides
agree on the server, the firewall, the Docker install, the backup scheme and the
port plan; this guide only adds the Fieldjig stack and the shared-Caddy layout
(§7). Where they differ, the difference is named, not hidden — see §7 and §14.

## 1. The server

Contabo Cloud VPS 8, as listed at the time of writing (confirm in the Contabo
panel at purchase — headline prices are for the first 24 months and region, VAT
and term change the final figure). Same box Massing's guide describes; one
purchase, not two:

| Spec | Value |
| --- | --- |
| vCPU | 8 (shared) |
| RAM | 24 GB |
| Disk | 300 GB SSD (150 GB NVMe option on the same plan) |
| Port / traffic | 600 Mbit/s, unlimited traffic (fair-use) |
| Price | €14/mo (~$16) — **for both apps** |
| Snapshots | 3 included on this plan — a second layer, not the backup (§9) |
| OS | **Ubuntu 24.04 LTS** (pick this at order time; every command below assumes it) |

Why this size: the box runs two full stacks — Massing (API + worker + Postgres
17 + MinIO + Redis + web) and Fieldjig (PHP-FPM + nginx + MySQL 8 + queue worker
+ scheduler). 24 GB is what makes both stacks' default worker counts and the
generous memory headroom in §10 affordable. The resource-limit blocks in the
compose file stay **off** by default on purpose (a too-low cap OOM-kills a
legitimate request mid-run); §10 says when to turn them on.

## 2. First boot — OS, access, firewall, DNS

```bash
# 1. Log in as root over SSH (Contabo mails the root password / key at provisioning),
#    update, and make a deploy user — do not run either stack as root.
apt-get update && apt-get upgrade -y
adduser deploy && usermod -aG sudo deploy

# 2. Firewall: SSH + HTTP + HTTPS only. Nothing else is ever opened on this box.
#    (Contabo also has a cloud firewall in its panel — if you enable it, allow the same
#    three there; a cloud rule that blocks 443 will break certificate issuance in §7.)
ufw allow OpenSSH
ufw allow 80,443/tcp
ufw enable
ufw status numbered   # expect: 22, 80, 443 — and nothing else

# 3. DNS: an A record for EACH public hostname pointing at the VPS IPv4 address.
#    Do this BEFORE the first boot of either stack — Caddy cannot issue a
#    certificate for a name that does not resolve to this box (§7).
#    Example:  build.example.com   A  203.0.113.10   (Fieldjig / SadSha Build)
#              pro.example.com     A  203.0.113.10   (Massing / SadSha Build Pro)
```

Pick both hostnames now and keep them: each becomes a `*_DOMAIN` in §4, each is
baked into its TLS certificate, and changing one later means re-issuing.
`app.example.com` is used below as the placeholder — replace it everywhere with
the real names. **Do not invent production domains in the repo** — the
placeholders stay placeholders until Qasim chooses the real names.

## 3. Install Docker + Compose

Docker's own apt repository (do not use the distro `docker.io` package or a snap
— both have shipped stale Compose versions on Ubuntu). Same install as Massing's
guide §3 — run once per box, not once per app:

```bash
apt-get install -y ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
  https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | tee /etc/apt/sources.list.d/docker.list > /dev/null
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

docker --version          # expect: Docker version 2x.x.x
docker compose version    # expect: Docker Compose version v2.x.x
usermod -aG docker deploy # log out and back in for this to apply
```

## 4. Get the code and configure it

```bash
# As the deploy user, in /srv. Massing already lives at /srv/sadsha-build-pro
# (its guide §4) — Fieldjig goes beside it, not inside it. The backup crons
# in §9 assume these exact paths, so write them down.
sudo mkdir -p /srv && sudo chown deploy:deploy /srv && cd /srv
git clone https://github.com/fnjlabs-arch/fieldjig.git sadsha-build
cd sadsha-build
git checkout remix/fieldjig-hosting
git log --oneline -1   # record the commit you deployed; put it in the runbook

cp .env.example .env
```

Now edit `.env`. Every variable the stack needs is already in the template with a
safe placeholder — generate real secrets, do not reuse the placeholders:

```bash
openssl rand -hex 32   # run once per secret: APP_KEY is generated differently, see below
openssl rand -hex 24   # run once per password: DB_PASSWORD, DB_ROOT_PASSWORD
# APP_KEY is NOT an openssl secret — generate it from inside the app container
# after first boot:  docker compose exec app php artisan key:generate --show
# (paste the `base64:...` value into .env; never reuse a dev key).
```

### 4.1 Required variables

| Variable | What it is | Notes |
| --- | --- | --- |
| `FIELDJIG_DOMAIN` | Public hostname for SadSha Build, e.g. `build.example.com` | **Required** — `APP_URL` is derived from it; DNS A record must already point here (§2). Placeholder `app.example.com` until the real name is chosen. |
| `MASSING_DOMAIN` | Public hostname for SadSha Build Pro | **Required** — the shared Caddyfile needs both names (§7). Its value is owned by Massing's guide; keep the two guides in agreement. |
| `FIELDJIG_WEB_PORT` | Host port for this app's nginx | Default `8081`; bound to localhost only. Massing uses `8080` — do not change either without checking §8. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL database + user + password | Defaults `sadsha_build` / `sadsha_build`. `DB_PASSWORD` is required in prod (fail-closed — see below). |
| `DB_ROOT_PASSWORD` | MySQL root password | Required by the MySQL image on first init; also fail-closed. |
| `APP_KEY` | Laravel encryption key | **Required in prod** — unset means sessions/cookies cannot be encrypted and the app fails closed at boot. |
| `APP_ENV` / `APP_DEBUG` | Environment + debug | Forced to `production` / `false` by the compose file; do not override in `.env`. |
| `MAIL_*` | Outbound mail (mailer, host, credentials, from address) | `.env.example` ships `MAIL_MAILER=log` (dev). **Password resets and verification emails do nothing in prod until a real mailer is configured** — set this before the pilot, or the first user cannot reset a password. |
| `GOOGLE_VISION_API_KEY` | Plans OCR (optional) | Only if Plans OCR is wanted; core flows work without it (clean report §5). |
| `CPANEL_*` | cPanel mailbox/webmail features (optional) | Only if the cPanel features are wanted; core flows work without them. |

Fail-closed behaviour (the backstop behind this table): the MySQL image refuses
to initialise when `DB_PASSWORD` / `DB_ROOT_PASSWORD` are empty, and Laravel
refuses to boot without `APP_KEY`. If you bypass the checks, the containers
still will not serve an open deployment by accident — but fix the environment
and re-run anyway; do not proceed with a failing stack.

### 4.2 The database — MySQL 8, not SQLite

`DB_CONNECTION=mysql`, `DB_HOST=mysql` (the compose service name) are set by the
compose file itself — you do not set them in `.env`. The `.env.example` default
is still `sqlite`: that is the **dev/test** default (the clean report verified
the SQLite path only), and the prod block in `.env.example` is commented out
precisely so a dev checkout never accidentally points at MySQL. In the compose
deploy the compose value wins and the `.env` value is ignored for these two
keys — same pattern as Massing's derived `DATABASE_URL`.

Two traps, both learned from Massing's Postgres:

- **Changing `DB_*` after first boot does not change the existing database.**
  The `mysql-data` volume keeps the original credentials; editing `.env` afterwards
  only makes the app fail to log in. To rotate credentials, change them inside
  MySQL (or recreate the volume and restore from backup, §9).
- **The settings SQLite file is not a second database to back up separately.**
  `SETTINGS_DB_PATH` is pointed at `/var/www/html/storage/app/settings.data`
  inside the storage volume, so the storage backup in §9 covers it. Do not
  point it at the repo directory — the container's code directory is replaced
  on every rebuild.

### 4.3 SaaS variables — present, not yet live

| Variable | Default | Meaning |
| --- | --- | --- |
| `SAAS_MODE` | `0` | `1` = SaaS mode (org scoping + trial limits + billing panel). Default off; dev behaviour unchanged. |
| `BILLING_PROVIDER` | `lemonsqueezy` | Provider name. Paddle is the later swap, not built now. |
| `LEMONSQUEEZY_API_KEY` | _(empty)_ | Lemon Squeezy API key — Test mode key until the store is approved. |
| `LEMONSQUEEZY_STORE_ID` | _(empty)_ | Lemon Squeezy store id (Toolzo store, once approved). |
| `LEMONSQUEEZY_VARIANT_PRO` | _(empty)_ | Variant id for the **$99/mo Pro plan** — from the Lemon dashboard **after** approval. One plan = one variant. |
| `LEMONSQUEEZY_WEBHOOK_SECRET` | _(empty)_ | HMAC secret for Lemon Squeezy webhook signature verification. |
| `SADSHA_BUILD_PRICE_MONTHLY` | `99` | Flat monthly price in USD. Configurable value (Remixer design, shared rules) — only Qasim changes it. |

These are passed through the compose stack to the app, queue and scheduler
today, but **nothing reads them yet** — the tenancy, billing, signup and
permission code is separate Remixer work. Empty defaults mean "SaaS features
off" and change nothing in the current build.

## 5. The stack as it will run

`docker-compose.yml` (single file — this is the prod shape; there is no dev
overlay). Bring-up is always `docker compose up -d --build` from `/srv/sadsha-build`.

| Service | Image | Ports (prod) | Volumes | Healthcheck |
| --- | --- | --- | --- | --- |
| `mysql` | `mysql:8.0` | 127.0.0.1:3306 | `mysql-data` | `mysqladmin ping` (uses `DB_USERNAME`/`DB_PASSWORD` from env) |
| `app` | built from `deploy/Dockerfile` (php:8.4-fpm) | — (internal only) | `storage-data` | `php artisan about` |
| `web` | same image, runs nginx | 127.0.0.1:`FIELDJIG_WEB_PORT` (8081) | `storage-data` (ro) | `curl` on `/up` (Laravel's built-in health route) |
| `queue` | same image, `queue:work` | — (serves nothing) | `storage-data` | disabled (binds no port; a healthcheck would never pass) |
| `scheduler` | same image, `schedule:work` | — (serves nothing) | `storage-data` | disabled (same reason) |
| `caddy` | `caddy:2` | **80, 443 (the only public ports, host network)** | `caddy-data` (certs), `caddy-config` | — (gates on web healthy via the vhost) |

Notes on the table, each of which was a real gap before this guide (§14):

- **MySQL 8 is prod; SQLite was dev-only.** The clean report verified SQLite
  locally (clean report §2). The compose stack never touches SQLite for the
  main database — `DB_CONNECTION=mysql` is forced by the compose file.
- **The queue worker is not optional.** Session, cache and queue are all
  database-backed (the repo defaults in `config/{session,cache,queue}.php` —
  no Redis on this box, by design), and with no worker running every enqueue
  succeeds, every caller is told the work is under way, and none of it
  happens. Nothing raises. If jobs back up, scale it (`--scale queue=2`).
- **The scheduler must run exactly once.** `schedule:work` ticks every minute
  inside its container, so no host cron entry is needed — but never scale it
  past 1 replica, or scheduled jobs (incl. `scheduler:run-dynamic-tasks`) run
  twice.
- **`web` and `app` share one image on purpose.** The image ships php-fpm AND
  nginx AND the app code; `app` runs php-fpm, `web` runs nginx. A separate
  nginx image would have no copy of `public/` at all. `public/build/` (Vite
  manifest + hashed assets) is committed in the repo, so the image needs no
  Node build — but a release that changes frontend source must commit a
  rebuilt `public/build/` first, or the deploy serves stale assets.
- **Migrations are not auto-run at startup.** A normal update needs an
  explicit `docker compose exec app php artisan migrate --force` (§10). Never
  run `migrate:fresh` / `migrate:reset` in production (the manual deploy
  checklist forbids them too).
- **Never run the demo seed against production.** `SEED_DEMO_DATA` stays
  `false`; the demo seeder creates `admin@example.com` / `password` and 37
  demo users. The first real admin is created by the tinker command in §6
  step 6, modelled on `UserSeeder` — not by seeding.

## 6. Bring it up

```bash
cd /srv/sadsha-build

# 1. Render the merged config and read it before trusting it. This is where a bad
#    interpolation, a missing required variable, or a wrong port shows up — cheaply.
docker compose config > /tmp/fieldjig-compose-resolved.yml
grep -E "FIELDJIG_DOMAIN|MASSING_DOMAIN|DB_CONNECTION|SAAS_MODE|LEMONSQUEEZY" /tmp/fieldjig-compose-resolved.yml

# 2. Build + start, detached. (First boot also pulls mysql:8.0 and caddy:2.)
docker compose up -d --build

# 3. Watch it become healthy (mysql first, then app, then web, then caddy).
docker compose ps
docker compose logs -f app

# 4. Run migrations, then cache config (never cache config at build time —
#    the runtime .env is not present in the image).
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app php artisan storage:link

# 5. Verify from inside the box, then from the outside.
curl -s http://localhost:8081/up          # expect {"status":"ok"} via nginx
curl -s https://build.example.com/up     # expect {"status":"ok"} via Caddy (real domain)
curl -s https://build.example.com/ | head -c 200   # expect the login page HTML

# 6. Create the first admin (run once; modelled on UserSeeder — do NOT run
#    the demo seeder). Replace the email/name/password placeholders.
docker compose exec app php artisan tinker --execute '
$user = App\Core\Identity\Models\User::updateOrCreate(
  ["email" => "owner@example.com"],
  ["first_name" => "Owner", "last_name" => "Admin", "username" => "owner",
   "password" => Illuminate\Support\Facades\Hash::make("CHANGEME-strong-password"),
   "email_verified_at" => now(), "is_admin" => true, "is_built_in" => true,
   "is_active" => true, "password_change_required" => true]);
$adminRoleId = App\Core\Auth\Role\Models\Role::query()
  ->where("name", App\Core\Auth\Role\Models\Role::BUILT_IN_ADMIN)->value("id");
if ($adminRoleId !== null) { $user->roles()->syncWithoutDetaching([$adminRoleId]); }
'
# Then sign in at https://build.example.com/login (Fortify `login` field:
# email OR username — `email=` alone fails, see clean report §4).
```

If step 5's public check fails but the localhost check passes, the problem is
Caddy or DNS (§7, §13), not the app. If step 4's migrate fails on login, the
`DB_*` values in `.env` do not match the volume's first-boot values (§4.2).

## 7. Caddy — reverse proxy and automatic HTTPS (shared, both apps)

`deploy/Caddyfile.shared` is the whole proxy config for the box:

```caddyfile
{$MASSING_DOMAIN} {
	encode zstd gzip
	reverse_proxy 127.0.0.1:{$MASSING_WEB_PORT:8080}
}

{$FIELDJIG_DOMAIN} {
	encode zstd gzip
	reverse_proxy 127.0.0.1:{$FIELDJIG_WEB_PORT:8081}
}
```

- **One Caddy, two vhosts.** This file is the only proxy on the shared box. It
  does **not** replace Massing's own `deploy/Caddyfile` — that file describes a
  standalone Massing deploy (one vhost) and stays correct for a Massing-only
  box. On the shared box, Massing's Caddy is scaled to 0 and this file is the
  proxy. If Massing ever moves off the shared box, its own Caddyfile is the one
  to use again, untouched. **This is the one place the two guides overlap —
  if Massing's guide changes its web port or its Caddy layout, this file and
  §8 must change with it.**
- **Why the Caddy service runs with `network_mode: host`.** Both apps' web
  containers are bound to 127.0.0.1 (Massing 8080, Fieldjig 8081), and a
  bridge-networked container cannot reach a localhost-bound port via
  `host.docker.internal` (that resolves to the gateway IP, not loopback).
  Host networking lets this Caddy reach 127.0.0.1:8080/8081 directly, without
  joining either stack's network or depending on container names. It binds
  80/443 on the host — which is exactly why Massing's own Caddy must be
  scaled to 0 on the shared box (below).
- **Starting Massing on the shared box** (no Massing file is edited — this is a
  run-time flag only):

  ```bash
  cd /srv/sadsha-build-pro
  docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile full up -d --build --scale caddy=0
  ```

  Everything else in Massing's guide (§6, §10) runs unchanged; only its Caddy
  stays down, because the shared Caddy in this stack already owns 80/443.
- Caddy fetches and renews a Let's Encrypt certificate for each `$*_DOMAIN`
  automatically on first request; there is no manual cert step and no renewal
  cron. Certificates and the ACME account live in the `caddy-data` volume,
  which is part of the backup (§9) — without it, a restore forces fresh
  issuance against Let's Encrypt's rate limit (50 certificates per domain per
  week). **On the shared box the Fieldjig backup is the only copy of the TLS
  data:** Massing's own Caddy is scaled to 0, so its backup script finds no
  Caddy container and skips the certs. Keep the Fieldjig backup off-box —
  it is what protects both apps' certificates.
- If a certificate never arrives: DNS is the usual cause (the A record does
  not point at this box, or it was created after Caddy first tried — Caddy
  retries, but check `docker compose logs caddy` for the ACME error). The
  other usual cause is port 80 blocked (ACME HTTP-01 needs it) — check UFW
  (§2) and the Contabo cloud firewall.

## 8. Firewall and exposure — what is public, what is not

UFW on the host allows **22, 80, 443 only** (§2). The compose files add a second
layer: every internal port is bound to localhost, so even with the host firewall
off, nothing but Caddy listens on the public interface.

| Port | Service | Reachable from |
| --- | --- | --- |
| 80 / 443 | Caddy (shared) | Internet (the only public ports) |
| 8080 | Massing web/nginx | `127.0.0.1` only — Caddy reaches it over loopback |
| 8081 (`FIELDJIG_WEB_PORT`) | Fieldjig web/nginx | `127.0.0.1` only — Caddy reaches it over loopback |
| 3306 | MySQL 8 (Fieldjig) | `127.0.0.1` only — backups use `docker compose exec`, not this port |
| 5432 | Postgres 17 (Massing) | `127.0.0.1` only |
| 8000 | Massing API | `127.0.0.1` only |
| 9000 / 9001 | MinIO API / console (Massing) | `127.0.0.1` only — reach the console via `ssh -L 9001:localhost:9001 deploy@<host>` |

Verify after first boot:

```bash
ss -tlnp | grep -E ':80|:443|:8080|:8081|:3306|:5432|:8000|:9000|:9001'
# 80 and 443 listen on 0.0.0.0; everything else must show 127.0.0.1.
```

Two exposure notes that survive the firewall:

- **Telescope is a dev tool.** `laravel/telescope` is installed (require-dev in
  `composer.json`, so the prod image's `--no-dev` install excludes it) — but
  confirm it stays out of the prod image after the first build
  (`docker compose exec app ls vendor/laravel/telescope` must fail). If a
  future release ever ships it in prod, gate it behind auth before any
  customer touches the box.
- **`/up` is unauthenticated by design** (Laravel's health route). It answers
  `{"status":"ok"}` and nothing else — that is the whole point of a health
  endpoint, but do not add anything sensitive behind it.

## 9. Backups

`scripts/backup-fieldjig.sh` (run from the repo root, with the stack up) is the
backup runbook, mirroring Massing's `scripts/backup.sh` scheme (one timestamped
tarball, `MANIFEST.txt`, volumes via `--volumes-from`) so the two apps' backups
look the same on the shared box. What a backup contains:

| Piece | How | Where it lands |
| --- | --- | --- |
| MySQL 8 | `mysqldump --single-transaction` (consistent snapshot) | `db.sql.gz` in the tarball |
| Laravel storage | tar of the `storage-data` volume via a throwaway container | `storage-data.tgz` (uploads, documents, logs, **and `settings.data`**) |
| Caddy TLS data | tar of the `caddy-data` volume (certs + ACME account) | `caddy-data.tgz` (both apps' certs — on the shared box this is the ONLY copy; Massing's backup skips TLS data because its Caddy is scaled to 0) |

```bash
# Back up now (→ ./backups/fieldjig-backup-<timestamp>.tgz)
./scripts/backup-fieldjig.sh

# Nightly at 02:30 — half an hour after Massing's 02:00 backup, so the two
# dumps never contend for disk I/O. Prune archives older than 14 days.
0 2 * * *  cd /srv/sadsha-build-pro && ./scripts/backup.sh >> /var/log/massing-backup.log 2>&1
0 2 * * *  find /srv/sadsha-build-pro/backups -name 'aec-backup-*.tgz' -mtime +14 -delete
30 2 * * * cd /srv/sadsha-build && ./scripts/backup-fieldjig.sh >> /var/log/fieldjig-backup.log 2>&1
0 3 * * *  find /srv/sadsha-build/backups -name 'fieldjig-backup-*.tgz' -mtime +14 -delete

# Restore — MANUAL, no restore script is shipped yet (see §12 item 5).
# Outline (drill this into a scratch stack before go-live, §9 rules):
#   1. docker compose down                     # stop the app first
#   2. Extract the tarball; mysql < db.sql.gz  # via `docker compose exec -T mysql mysql ...`
#   3. Restore storage-data + caddy-data from their .tgz files into fresh volumes
#   4. docker compose up -d; curl https://<domain>/up
```

Three rules (same as Massing's guide):

1. **Do one restore drill into a scratch stack before go-live**, and hit `/up`
   plus a known project before trusting any backup. A backup that has never
   been restored is a hope, not a backup. Verify with the same two curls as
   §6 step 5.
2. **Keep backups off-box** (`aws s3 cp`, `rclone`, or plain `scp` to another
   machine). A backup on the same disk dies with the disk.
3. **Contabo snapshots are the second layer, not the backup.** A snapshot
   restores the whole disk to a point in time; it does not give you a single
   database or a single project, and it lives in the same account as the VM.
   Daily `backup-fieldjig.sh` + off-box copy is the recovery plan; snapshots
   are for "the whole box is broken" days.

## 10. Updates and restarts — the runbook

```bash
cd /srv/sadsha-build

# --- Routine update (new commit on the deploy branch) ---
git pull --ff-only
docker compose up -d --build
docker compose exec app php artisan migrate --force   # only if the release ships migrations
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app php artisan queue:restart
docker compose ps
curl -s http://localhost:8081/up

# --- Restart one service (config change, wedged queue worker) ---
docker compose restart queue
# --- Restart everything (keeps volumes; data is untouched) ---
docker compose restart
# --- Full stop / start ---
docker compose down        # stops + removes containers; volumes survive
docker compose up -d --build
```

Rules and notes:

- **Deploy from a recorded commit.** `git log --oneline -1` before and after; if
  the new build misbehaves, `git checkout <previous-commit>` + `up -d --build`
  is the rollback (plus a §9 restore if data itself is the problem — never roll
  back data by hand).
- **Database migrations are not auto-run at startup.** A release that ships a
  migration says so in its notes; run it from the running container as above.
  The tenancy/billing migrations (Remixer Agents T/B) are additive by design —
  a rollback of code without a restore of data is safe only while they stay
  additive; check the release notes.
- **Restart policies are `unless-stopped`** on every service, so a host reboot
  brings the stack back by itself. Verify once: `sudo reboot`, then check
  `docker compose ps` after the box comes back. (Massing's stack has the same
  policy — both stacks come back together.)
- **Smoke test after every update:** log in, open the dashboard, open one
  project, create one timecard, create one change order (the write path the
  clean report proved live, §4). If the pilot is live, do this as the pilot
  builder's own account, not as the platform admin.
- **Logs:** `docker compose logs -f app` (Laravel log via the `stack` channel),
  `docker compose logs -f queue`, `docker compose logs -f scheduler`,
  `docker compose logs -f caddy`. Watch the queue logs for failed jobs
  (`php artisan queue:failed` lists them; `queue:retry` re-runs one).
- **Resource limits:** the commented-out `deploy.resources.limits` blocks stay
  off unless you have measured the host under load. If you turn them on, size
  **MySQL first** (that is where the working set lives) and keep the app
  generous. A cap that is too low OOM-kills a legitimate request mid-run —
  that failure looks like a bug, not a limit. Revisit the server size only
  when one of these is true: sustained RAM pressure (watch `docker stats`),
  queue depth that `--scale queue=2` does not clear, or MySQL/storage disk
  use approaching the 300 GB disk (documents + plan sheets accumulate —
  check `docker system df -v` monthly).
- **Updating one app never restarts the other.** The two stacks are separate
  compose projects; `docker compose restart` in `/srv/sadsha-build` touches
  Fieldjig only. The one exception is the shared Caddy: a Caddyfile change
  (new hostname, new vhost) restarts the proxy for both apps — announce it,
  and do it outside the pilot builder's working hours.

## 11. Sizing and cost — the margin math

| | |
| --- | --- |
| Server (Contabo VPS 8, shared) | ~$16/mo (against the $25/mo budget, for **both** apps) |
| Price per Fieldjig customer | $99/mo flat (Pro); trial free for 30 days |
| **Margin per paying Fieldjig customer** | **~$83/mo before support** (and before Massing's share of the box) |
| Customers to cover the shared server | 1 Fieldjig customer (covers it ~6× over); 1 of each app covers it ~18× over |

The box is not the constraint at this price: 8 vCPU / 24 GB runs both stacks
with room for several concurrent projects per app. The constraint is support —
onboarding a small builder, answering timecard/payroll questions, and the first
live project. Budget for that, not for a bigger VM. (Massing's own margin math
— $199/mo, ~$183 before support — lives in its guide §11; the two margins
share one €14 server, they do not each pay for one.)

## 12. What is NOT verified — the honest list

This guide is validated as far as local checks allow (see "Status" at the top).
Everything in this section needs a box with Docker, and none of it may be
assumed from reading the files:

1. **The Docker full stack has never been run.** `docker compose up --build`
   must be run once on the VPS (or any Docker box) before this guide is
   trusted — the clean report verified the SQLite dev path only, and this
   guide's author had no Docker at all. First-run proof: every service
   reaches `healthy`/`running` in `docker compose ps`, `GET /up` answers
   through Caddy, and the smoke test in §10 passes.
2. **The shared-Caddy layout has never run.** The `network_mode: host` Caddy
   reaching both localhost-bound web ports, and Massing's stack running with
   `--scale caddy=0`, are reasoned from Docker's networking rules, not proven.
   First-boot proof: both hostnames answer over HTTPS with the other stack's
   Caddy down (`docker compose ps` in `/srv/sadsha-build-pro` shows no
   `caddy` container).
3. **The MySQL 8 first boot is unproven.** The `mysqladmin ping`
   healthcheck, the empty-password fail-closed behaviour, and the
   `DB_*`-after-first-boot trap (§4.2) are all per the MySQL image docs, not
   observed here. If `mysql` sits `unhealthy` while the container runs, the
   healthcheck probe — not MySQL — is the suspect.
4. **The first-admin tinker command (§6 step 6) is unrun.** It is copied from
   `UserSeeder` (same model, same fields, same role sync), but no one has
   executed it against a MySQL-backed install. If it fails, fall back to
   running `UserSeeder` alone — and change the `password` password
   immediately after first login.
5. **There is no restore script yet.** The backup script is written and
   syntax-checked; the restore is a manual outline in §9. The restore drill
   (rule 1 in §9) is owed before go-live, and a `restore-fieldjig.sh` is the
   follow-up if the manual outline proves fiddly.
6. **Billing, tenancy, signup and the onboarding permission model are not
   built.** The env vars are plumbed (§4.3), the code is not. The
   store-approval blocker (§0) is still open; the $99 price is decided by
   Mili on Qasim's "As you wish" delegation, open to his override.
7. **Mail is not configured.** `MAIL_MAILER=log` is the shipped default;
   password resets and verification emails will not reach anyone until a
   real mailer is set (§4.1). Do not pilot without it.
8. **The full Pest suite was never run to completion against this stack**
   (1,116/1,200 passed at the clean-report cutoff; 82 failed, mostly test
   drift + environment). The Remixer must re-run it after the tenancy
   refactor; this guide's YAML/env checks are not a substitute.

## 13. Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `docker compose up` fails pulling `mysql:8.0` / `caddy:2` | Registry or network issue on first pull | Retry the pull; `docker pull mysql:8.0` alone shows the real error |
| `mysql` never becomes `healthy` | `DB_PASSWORD` empty/mismatched, or healthcheck probe wrong | Check `docker compose logs mysql`; confirm the three `DB_*` vars match the volume's first-boot values (changing them after first boot does **not** change the existing database — §4.2) |
| `app` exits on boot, no `APP_KEY` | `APP_KEY` unset in `.env` | Generate it (§4) and re-run `up -d`; never reuse a dev key |
| `web` healthy but `/up` returns 502 | App not ready yet on first boot | Wait — web gates on app healthy; if it persists, `docker compose logs app` |
| `caddy` healthy but site shows Caddy's default / cert error | DNS not pointing at the box, or port 80 blocked for ACME | Check the A records (§2) and `docker compose logs caddy`; confirm UFW + cloud firewall allow 80/443 |
| Both sites down after Massing was updated | Massing's update re-started its own Caddy, which grabbed 80/443 | `docker compose --scale caddy=0` on the Massing stack (§7), then restart this stack's Caddy |
| Jobs enqueue but nothing happens | Queue worker not running | `docker compose ps` — queue must be up; check `php artisan queue:failed` |
| Scheduled jobs never fire | Scheduler scaled to 0 or crashed | Exactly one `scheduler` replica must be running; check its logs |
| Disk filling up | Documents + plan sheets + old images | `docker system df -v`; `docker image prune`; check volume sizes before adding disk |
| Login fails with `email=` | Fortify uses a `login` field (email OR username) | Use `login=<email-or-username>&password=...` (clean report §4) |
| Plain User 403s on every core page | The built-in User role has 0 of 191 permissions by design | Expected until the Remixer §4 onboarding fix lands — pilot as the org Admin (first user = Admin role) |

---

## 14. Appendix — gaps found in the Sail compose / docs (fixed by this branch)

The design doc asked for a review of Fieldjig's Sail `docker-compose.yml` and
deploy notes against this guide. The review found the compose file did not
exist at all — and the docs had no container path. Every gap, and what this
branch did about it:

| # | Gap | Fix in this branch |
| --- | --- | --- |
| 1 | **No `docker-compose.yml` ever existed** (verified: `git log --all` for the filename is empty; Sail is only a `require-dev` entry, `laravel/sail ^1.41` in `composer.json`) | Created `docker-compose.yml` — the first compose stack this repo has had (§5). |
| 2 | **Sail was never published** (`php artisan sail:install` never run) — no dev compose to adapt, no healthchecks, no volumes anywhere | New stack written for prod directly, with healthchecks on mysql/app/web (§5). Dev stays `php artisan serve` + SQLite; no Sail file is faked. |
| 3 | **`.env.example` was SQLite-only** — no prod `DB_*` block, no MySQL notes | Prod MySQL block added (commented, `DB_PASSWORD` required), plus `SETTINGS_DB_PATH` note (§4.2). |
| 4 | **No SaaS env vars anywhere** | `SAAS_MODE`, `BILLING_PROVIDER`, `LEMONSQUEEZY_*`, `SADSHA_BUILD_PRICE_MONTHLY=99` added to `.env.example` and plumbed through compose (§4.3). Placeholders only. |
| 5 | **No healthchecks** in any deploy doc (the manual-SSH checklist only checks `php artisan about` by hand) | Healthchecks on mysql (`mysqladmin ping`), app (`artisan about`), web (`/up`); queue/scheduler deliberately unchecked (no port), same as Massing's worker. |
| 6 | **No persistent-volume story in compose** — the deploy docs say "`storage/` is persistent" and "`SETTINGS_DB_PATH` points to a persistent location" but nothing enforced it | Named volumes `mysql-data` + `storage-data`; `SETTINGS_DB_PATH` forced inside the storage volume by the compose file (§5). |
| 7 | **Deploy docs are manual-SSH / cPanel only** (`MANUAL_SSH_DEPLOY_CHECKLIST.md`, `CPANEL_ROLLOUT_PLAN.md`, `PRODUCTION_PROMOTION_AND_DEPLOY_CHECKLIST.md`) — no container path, no reverse proxy, no TLS | This guide is the container path; the manual checklists are left untouched (they remain valid for a non-Docker deploy). |
| 8 | **No backup script** — the manual checklist backs up with a commented-out `mysqldump` placeholder | `scripts/backup-fieldjig.sh` added, mirroring Massing's `backup.sh` scheme (§9). Restore script still owed (§12 item 5). |
| 9 | **No fail-closed secret story** — `.env.example` shipped empty `APP_KEY` / `DB_PASSWORD` with no note on what happens unset | Fail-closed behaviour documented (§4.1) and enforced by the MySQL image + Laravel boot. |
| 10 | **Queue + scheduler had no container story** — dev runs them as `composer run dev` concurrent processes | Dedicated `queue` and `scheduler` services (§5); scheduler pinned to one replica. |
| 11 | **No shared-box coordination** — nothing said how Fieldjig and Massing share one VPS (ports, proxy, backup times) | Shared Caddy (§7), port plan (§8), staggered backup crons 02:00 / 02:30 (§9). |
| 12 | **`SEED_DEMO_DATA` / demo credentials risk** — the demo seeder is the only documented way to get an admin user | Documented as forbidden in prod; first-admin tinker command added (§6 step 6). |

---

*Companion docs: Massing's `docs/saas/contabo-vps8.md` (in the Massing
workbench repo — the same box, the other app; read it first if you have not);
Fieldjig's [MANUAL_SSH_DEPLOY_CHECKLIST](../development/MANUAL_SSH_DEPLOY_CHECKLIST.md)
(the non-Docker deploy path, still valid); the clean report and the Remixer
design doc in the factory workbench (not committed to this repo). Source of
truth for the product decisions: the Remixer design doc §5 and the clean report.*
