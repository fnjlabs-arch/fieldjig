#!/usr/bin/env bash
# Back up SadSha Build (Fieldjig): MySQL 8 (logical dump) + Laravel storage
# volume (uploads, documents, logs, settings.data) + shared Caddy TLS data.
# Produces one timestamped tarball under ./backups. Run from the repo root
# with the stack up. Restore is manual — see docs/saas/contabo-vps8-shared.md
# §9 (no restore script is shipped yet; the drill in §9 is the proof).
#
#   ./scripts/backup-fieldjig.sh [output_dir]
#
# Mirrors Massing's scripts/backup.sh scheme (one tarball, MANIFEST.txt,
# volumes via --volumes-from) so the two apps' backups look the same on the
# shared box. The two scripts must NOT be confused: this one dumps MySQL,
# Massing's dumps Postgres.
set -euo pipefail

OUT_DIR="${1:-./backups}"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

DBUSER="${DB_USERNAME:-sadsha_build}"
DBNAME="${DB_DATABASE:-sadsha_build}"
DC="docker compose"

echo "==> MySQL dump ($DBNAME)"
$DC exec -T mysql mysqldump -u "$DBUSER" -p"${DB_PASSWORD:-}" \
  --single-transaction --routines --events "$DBNAME" | gzip > "$STAGE/db.sql.gz"

# Laravel storage/ straight from its volume (no credentials needed).
# This volume holds uploads + documents AND settings.data (SETTINGS_DB_PATH
# points inside it — see docker-compose.yml), so one tar covers both.
APP_CID="$($DC ps -q app)"
[ -n "$APP_CID" ] || { echo "app container not running" >&2; exit 1; }

echo "==> storage volume"
docker run --rm --volumes-from "$APP_CID" -v "$STAGE":/bak alpine \
  tar czf /bak/storage-data.tgz -C /var/www/html/storage .

# Shared Caddy TLS state (caddy-data volume: issued certificates + ACME
# account). The certs are re-issuable via Let's Encrypt, but a restore
# without them forces fresh issuance against the rate limit (50 certificates
# per domain per week) and leaves both sites on HTTP until it succeeds —
# so back them up with the rest. On the shared box this is the ONLY copy:
# Massing's own Caddy is scaled to 0, so its backup script finds no Caddy
# container and skips the TLS data — the Fieldjig backup carries both apps'
# certificates, and keeping it off-box is what protects them.
CADDY_CID="$($DC ps -q caddy 2>/dev/null || true)"
if [ -n "$CADDY_CID" ]; then
  echo "==> Caddy TLS data"
  docker run --rm --volumes-from "$CADDY_CID" -v "$STAGE":/bak alpine \
    tar czf /bak/caddy-data.tgz -C /data . 2>/dev/null || echo "(no caddy data)"
fi

cat > "$STAGE/MANIFEST.txt" <<EOF
SadSha Build (Fieldjig) backup
created:   $TS
mysql:     db=$DBNAME user=$DBUSER (db.sql.gz, mysqldump --single-transaction)
storage:   storage-data.tgz (uploads, documents, logs, settings.data)
caddy:     caddy-data.tgz (TLS certificates + ACME account, shared with Massing)
restore:   manual — see docs/saas/contabo-vps8-shared.md §9
EOF

mkdir -p "$OUT_DIR"
tar czf "$OUT_DIR/fieldjig-backup-$TS.tgz" -C "$STAGE" .
echo "Wrote $OUT_DIR/fieldjig-backup-$TS.tgz"
