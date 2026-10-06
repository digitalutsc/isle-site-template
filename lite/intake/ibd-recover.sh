#!/usr/bin/env bash
#
# ibd-recover.sh <site> <tarball of .ibd files> <output .sql.xz>
#
# Rebuilds a database from raw MySQL 8.0 .ibd files (no ibdata1, no .cfg) and writes a
# normalized mysqldump, so that db-<site> can be seeded like from any other dump:
#
#   1. extract the archive to lite/sites/<site>/db-raw/
#   2. ibd2sdi (mysql:8.0-debian under amd64 emulation) + sdi2ddl.py -> schema.sql
#   3. throwaway mysql:8.0 container: import-ibd.sh attaches every table with
#      DISCARD/IMPORT TABLESPACE (tables matching SKIP_TABLES stay empty)
#   4. tables IMPORT TABLESPACE rejected (INSTANT DDL, e.g. file_managed): rows extracted
#      with ddcw/ibd2sql and loaded
#   5. mysqldump -> normalize-dump.sh -> <output>
#   6. container removed; db-raw/ deleted; logs kept in db/incoming/recovery-<date>/
#
# Reuses lite/intake/import-ibd.sh and sdi2ddl.py from the menus work unchanged.
set -euo pipefail

site="${1:?site}"; tarball="${2:?tarball}"; out="${3:?output}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
HERE="lite/intake"
SITE_DIR="lite/sites/$site"
RAW="$SITE_DIR/db-raw"
WORK="$RAW/work"
DB="${SITE_DB_NAME:-$site}"
SKIP="${SKIP_TABLES:-}"
NAME="lite-recover-$site"
IBD2SDI_IMAGE="${IBD2SDI_IMAGE:-mysql:8.0-debian}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
ROOT_PW="recover"
LOGDIR="$SITE_DIR/db/incoming/recovery-$(date +%Y%m%d-%H%M)"

say() { echo "   [ibd-recover] $*"; }
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

# 1. extract -------------------------------------------------------------------------------
rm -rf "$RAW"; mkdir -p "$RAW/extract" "$WORK/sdi" "$WORK/ddl" "$WORK/state"
say "extracting $(basename "$tarball")"
tar -xzf "$tarball" -C "$RAW/extract"
IBD_DIR="$(find "$RAW/extract" -name '*.ibd' -print0 | xargs -0 -n1 dirname | sort | uniq -c | sort -rn | head -1 | awk '{print $2}')"
[ -n "$IBD_DIR" ] || { echo "no .ibd files in $tarball" >&2; exit 1; }
count="$(find "$IBD_DIR" -maxdepth 1 -name '*.ibd' | wc -l | tr -d ' ')"
schema_ref="$(basename "$IBD_DIR")"
say "$count .ibd files in $IBD_DIR (source schema name: $schema_ref)"
IBD_ABS="$(cd "$IBD_DIR" && pwd)"; WORK_ABS="$(cd "$WORK" && pwd)"

# 2. schema from SDI ----------------------------------------------------------------------
say "ibd2sdi -> $WORK/sdi (amd64 emulation, ~1 min)"
docker run --rm --platform linux/amd64 -v "$IBD_ABS:/d:ro" -v "$WORK_ABS/sdi:/out" "$IBD2SDI_IMAGE" \
  bash -c 'for f in /d/*.ibd; do t=$(basename "$f" .ibd); [ -s "/out/$t.json" ] || ibd2sdi "$f" > "/out/$t.json"; done'
python3 "$HERE/sdi2ddl.py" --sdi-dir "$WORK/sdi" --out "$WORK/schema.sql" --ddl-dir "$WORK/ddl"
say "schema.sql: $(grep -c '^CREATE TABLE' "$WORK/schema.sql") tables, INSTANT-DDL warnings: $(grep -c 'WARNING' "$WORK/schema.sql" || true)"

# 3. throwaway MySQL 8.0 and tablespace import -------------------------------------------
cleanup
say "starting throwaway $MYSQL_IMAGE as $NAME"
docker run -d --name "$NAME" \
  -e MYSQL_ROOT_PASSWORD="$ROOT_PW" -e MYSQL_DATABASE="$DB" -e SKIP_TABLES="$SKIP" \
  -v "$IBD_ABS:/import:ro" -v "$WORK_ABS:/work" -v "$ROOT/$HERE/import-ibd.sh:/import-ibd.sh:ro" \
  "$MYSQL_IMAGE" --default-authentication-plugin=mysql_native_password --character-set-server=utf8mb4 \
  --collation-server=utf8mb4_0900_ai_ci --innodb-buffer-pool-size=1G --max-allowed-packet=256M >/dev/null
for i in $(seq 1 60); do
  docker exec "$NAME" mysqladmin ping -h127.0.0.1 -uroot -p"$ROOT_PW" --silent >/dev/null 2>&1 && break
  sleep 2; [ "$i" -eq 60 ] && { echo "mysqld did not come up" >&2; docker logs "$NAME" | tail -20; exit 1; }
done
say "importing tablespaces (import-ibd.sh; log in $WORK/import.log)"
docker exec "$NAME" bash /import-ibd.sh || say "import-ibd.sh reported failures; recovering them next"

# 4. INSTANT-DDL tables through ibd2sql ---------------------------------------------------
if [ -s "$WORK/failed.txt" ]; then
  while read -r table; do
    [ -n "$table" ] || continue
    say "recover-table $table (ddcw/ibd2sql)"
    docker run --rm -v "$IBD_ABS:/d:ro" -v "$WORK_ABS:/work" python:3.12-slim sh -c '
      python3 -c "import urllib.request,tarfile,io; tarfile.open(fileobj=io.BytesIO(urllib.request.urlopen(\"https://github.com/ddcw/ibd2sql/archive/refs/heads/main.tar.gz\").read())).extractall(\"/tmp\")" && \
      cd /tmp/ibd2sql-main && python3 main.py /d/'"$table"'.ibd --sql --complete-insert > /work/'"$table"'.sql' \
      2> "$WORK/$table.ibd2sql.log" || { say "ibd2sql failed for $table (see $WORK/$table.ibd2sql.log); table stays empty"; continue; }
    # ibd2sql qualifies inserts with the source schema name.
    if [ "$schema_ref" != "$DB" ]; then
      SR="$schema_ref" D="$DB" perl -pi -e 's/^INSERT INTO `\Q$ENV{SR}\E`\./INSERT INTO `$ENV{D}`./' "$WORK/$table.sql"
    fi
    docker exec "$NAME" bash -c "mysql -uroot -p'$ROOT_PW' $DB -e 'SET foreign_key_checks=0; TRUNCATE TABLE \`$table\`; SOURCE /work/$table.sql;'" \
      && say "$table: $(docker exec "$NAME" mysql -uroot -p"$ROOT_PW" -N "$DB" -e "SELECT COUNT(*) FROM \`$table\`" 2>/dev/null) rows"
  done < "$WORK/failed.txt"
fi

# 5. dump + normalize ---------------------------------------------------------------------
say "mysqldump $DB"
docker exec "$NAME" mysqldump -uroot -p"$ROOT_PW" --single-transaction --quick --routines --no-tablespaces --set-gtid-purged=OFF "$DB" \
  > "$WORK/$DB.sql"
"$HERE/normalize-dump.sh" "$WORK/$DB.sql" "$out"

# 6. logs, cleanup ------------------------------------------------------------------------
mkdir -p "$LOGDIR"
cp "$WORK/schema.sql" "$LOGDIR/" 2>/dev/null || true
cp "$WORK"/import.log "$WORK"/failed.txt "$WORK"/*.ibd2sql.log "$LOGDIR/" 2>/dev/null || true
{
  echo "source schema: $schema_ref ($count .ibd files)"
  echo "mysqld_version_id: $(python3 -c "import json,glob;f=sorted(glob.glob('$WORK/sdi/*.json'))[0];print(json.load(open(f)).get('mysqld_version_id'))" 2>/dev/null || echo '?')"
  echo "failed (recovered with ibd2sql):"; cat "$WORK/failed.txt" 2>/dev/null || true
} > "$LOGDIR/summary.txt"
cleanup
rm -rf "$RAW"
say "done; logs in $LOGDIR"
