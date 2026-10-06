#!/usr/bin/env bash
# Runs INSIDE the db container (as root). Creates every table from /work/schema.sql,
# then attaches the prod .ibd files one by one with DISCARD/IMPORT TABLESPACE.
#
#   /import  <site>/database (read-only .ibd files)
#   /work    <site>/import   (schema.sql, ddl/<table>.sql, state markers, logs)
#
# Idempotent: a table is skipped once /work/state/<table>.done exists.
# Tables whose name matches $SKIP_TABLES (extended regex, whole name) are left empty.
# A table whose import fails is re-created empty so Drupal can still boot; it is
# listed in /work/failed.txt for targeted recovery.
set -uo pipefail

DB="${MYSQL_DATABASE:?}"
DATADIR="/var/lib/mysql/${DB}"
SKIP="${SKIP_TABLES:-}"
STATE=/work/state
LOG=/work/import.log
FAILED=/work/failed.txt

sql() { mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" --batch --skip-column-names "$@"; }

until mysqladmin ping -h127.0.0.1 -uroot -p"${MYSQL_ROOT_PASSWORD}" --silent 2>/dev/null; do
    echo "waiting for mysqld..."; sleep 2
done

mkdir -p "${STATE}"
: > "${FAILED}"
echo "== $(date) creating tables from /work/schema.sql" | tee -a "${LOG}"
if ! sql "${DB}" < /work/schema.sql 2>>"${LOG}"; then
    echo "DDL failed, see ${LOG}"; exit 1
fi

imported=0; skipped=0; failed=0
for ibd in /import/*.ibd; do
    table=$(basename "${ibd}" .ibd)
    [ -f "${STATE}/${table}.done" ] && continue

    if [ -n "${SKIP}" ] && printf '%s\n' "${table}" | grep -Eqx "${SKIP}"; then
        echo "skip (left empty): ${table}"
        touch "${STATE}/${table}.done"; skipped=$((skipped + 1)); continue
    fi

    echo "-- ${table}"
    if ! sql "${DB}" -e "SET foreign_key_checks=0; ALTER TABLE \`${table}\` DISCARD TABLESPACE;" 2>>"${LOG}"; then
        echo "${table} discard" >> "${FAILED}"; failed=$((failed + 1)); continue
    fi
    cp "${ibd}" "${DATADIR}/${table}.ibd" && chown mysql:mysql "${DATADIR}/${table}.ibd" && chmod 640 "${DATADIR}/${table}.ibd"

    # Warning 1810 (no .cfg file, "import without schema verification") is expected.
    if sql "${DB}" -e "SET foreign_key_checks=0; ALTER TABLE \`${table}\` IMPORT TABLESPACE; SHOW WARNINGS;" >>"${LOG}" 2>&1 \
       && sql "${DB}" -e "CHECK TABLE \`${table}\` QUICK" | grep -qw OK; then
        touch "${STATE}/${table}.done"; imported=$((imported + 1))
    else
        echo "${table} import" >> "${FAILED}"; failed=$((failed + 1))
        echo "   FAILED (see ${LOG}); recreating ${table} empty"
        sql "${DB}" -e "DROP TABLE IF EXISTS \`${table}\`" 2>>"${LOG}"
        rm -f "${DATADIR}/${table}.ibd"
        sql "${DB}" < "/work/ddl/${table}.sql" 2>>"${LOG}"
    fi
done

echo "== imported=${imported} skipped=${skipped} failed=${failed} (details: ${FAILED}, ${LOG})"
sql -e "SELECT COUNT(*) AS tables_in_${DB} FROM information_schema.tables WHERE table_schema='${DB}'"
for table in node_field_data media_field_data file_managed users_field_data; do
    sql "${DB}" -e "SELECT '${table}', COUNT(*) FROM \`${table}\`" 2>/dev/null || echo "${table} (table does not exist)"
done
[ "${failed}" -eq 0 ]
