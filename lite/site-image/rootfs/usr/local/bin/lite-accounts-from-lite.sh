#!/command/with-contenv bash
# shellcheck shell=bash
#
# Replace the accounts of this production replica with the accounts of the Lite dev site.
#
# Production handovers may contain the account tables; the replica must never use them
# (password hashes). normalize-dump.sh already drops their rows; this script fills the
# tables from the Lite site's MariaDB (same stack, service "mariadb", database
# drupal_default) so uid 0/1, roles and the stack's admin password apply here too.
# Runs inside drupal-<site> at first start (lite-site-finalize.sh) and on demand
# (`make site-users-from-lite SITE=<site>`). Idempotent: tables are truncated first.
set -euo pipefail

readonly SRC_HOST="${LITE_ACCOUNTS_SOURCE_HOST:-mariadb}"
readonly SRC_PORT="${LITE_ACCOUNTS_SOURCE_PORT:-3306}"
readonly SRC_DB="${LITE_ACCOUNTS_SOURCE_DB:-drupal_default}"
readonly DST_HOST="${DB_MYSQL_HOST:-${DRUPAL_DEFAULT_DB_HOST:-mariadb}}"
readonly DST_PORT="${DB_MYSQL_PORT:-3306}"
readonly DST_DB="${DRUPAL_DEFAULT_DB_NAME:?}"
readonly ROOT_PW="${DB_ROOT_PASSWORD:?}"
TABLES="users users_field_data users_data user__roles user__user_picture shortcut_set_users"

cd /var/www/drupal

# Tables may be missing when a handover left them out entirely.
php /opt/lite/scripts/restore-user-tables.php >/dev/null 2>&1 || true

existing=""
for t in $TABLES; do
  if mysql -h "$DST_HOST" -P "$DST_PORT" -uroot -p"$ROOT_PW" -N "$DST_DB" -e "SHOW TABLES LIKE '$t'" 2>/dev/null | grep -qx "$t"; then
    existing="$existing $t"
  fi
done
[ -n "$existing" ] || { echo "no account tables in $DST_DB"; exit 1; }

echo "copying accounts from $SRC_HOST/$SRC_DB into $DST_HOST/$DST_DB:$existing"
# /*!999999 is a MariaDB-only sandbox directive that MySQL 8 rejects.
{
  echo "SET foreign_key_checks=0;"
  for t in $existing; do echo "TRUNCATE TABLE \`$t\`;"; done
  mariadb-dump -h "$SRC_HOST" -P "$SRC_PORT" -uroot -p"$ROOT_PW" --no-create-info --complete-insert --skip-triggers "$SRC_DB" $existing \
    | grep -v '^/\*!999999'
} | mysql -h "$DST_HOST" -P "$DST_PORT" -uroot -p"$ROOT_PW" "$DST_DB"

mysql -h "$DST_HOST" -P "$DST_PORT" -uroot -p"$ROOT_PW" -N "$DST_DB" -e "SELECT CONCAT('  accounts now: ', COUNT(*), ' (uid 1 = ', (SELECT name FROM users_field_data WHERE uid = 1), ')') FROM users_field_data WHERE uid > 0"
