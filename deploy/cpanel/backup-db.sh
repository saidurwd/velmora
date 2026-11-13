#!/usr/bin/env bash
# Daily database copy for shared hosting, run from cron. Credentials come from ~/.velmora-backup.cnf (create it once, `chmod 600`):
#   [client]
#   user=cpaneluser_dbuser
#   password=THE-DATABASE-PASSWORD
#   host=localhost
# Usage: bash backup-db.sh DATABASE_NAME      Keeps 14 days in ~/velmora-backups. Copy the newest file OFF the server too (download it, or use cPanel backup destinations).
set -euo pipefail
DB="${1:?database name}"; DIR="${VELMORA_BACKUPS:-$HOME/velmora-backups}"; mkdir -p "$DIR"; chmod 700 "$DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
mysqldump --defaults-extra-file="${VELMORA_CNF:-$HOME/.velmora-backup.cnf}" --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "$DB" | gzip > "$DIR/$DB-$STAMP.sql.gz.part"
mv "$DIR/$DB-$STAMP.sql.gz.part" "$DIR/$DB-$STAMP.sql.gz"
gzip -t "$DIR/$DB-$STAMP.sql.gz"   # a copy that cannot be read back is worse than none
find "$DIR" -name "$DB-*.sql.gz" -mtime +14 -delete
echo "$DIR/$DB-$STAMP.sql.gz"
