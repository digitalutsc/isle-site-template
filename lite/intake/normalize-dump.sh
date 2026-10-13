#!/usr/bin/env bash
#
# normalize-dump.sh <input dump> <output .sql.xz>
#
# Turns any mysqldump handover into the one form db-<site> loads on first start:
#   - decompressed from .gz / .xz / plain
#   - no CREATE DATABASE / USE (the data goes into MYSQL_DATABASE whatever the prod name was)
#   - no DEFINER clauses (views/triggers would otherwise need the prod MySQL users)
#   - no GTID / binlog session statements (they fail on a fresh server)
#   - NO ACCOUNT ROWS: the INSERTs of users, users_field_data (password hashes), users_data,
#     user__roles, user__user_picture and shortcut_set_users are dropped (tables kept, empty).
#     Production credentials never enter a replica; the first start copies the accounts of
#     the Lite dev site instead (lite-site-finalize.sh).
#   - recompressed with xz (gzip when xz is not installed; the mysql image loads both)
set -euo pipefail

ACCOUNT_TABLES='users|users_field_data|users_data|user__roles|user__user_picture|shortcut_set_users|sessions|flood'

in="${1:?input dump}"; out="${2:?output .sql.xz}"
[ -s "$in" ] || { echo "normalize-dump: $in is missing or empty" >&2; exit 1; }
mkdir -p "$(dirname "$out")"

decompress() {
  case "$1" in
    *.gz)  gzip -dc "$1" ;;
    *.xz)  xz -dc "$1" ;;
    *.zst) zstd -dc "$1" ;;
    *)     cat "$1" ;;
  esac
}

compress() {
  if command -v xz >/dev/null 2>&1; then xz -T0 -3 -c; else gzip -6 -c; fi
}

if ! command -v xz >/dev/null 2>&1; then
  out="${out%.xz}.gz"
  echo "normalize-dump: xz not installed, writing $out" >&2
fi

tmp="$out.tmp"
decompress "$in" \
  | ACCOUNT_TABLES="$ACCOUNT_TABLES" perl -pe '
      BEGIN { $tables = 0; $dropped = 0; $acct = qr/^(?:$ENV{ACCOUNT_TABLES})$/; }
      $tables++ if /^CREATE TABLE /;
      $_ = "" if /^CREATE DATABASE /;
      $_ = "" if /^USE `/;
      $_ = "" if /^SET \@\@(GLOBAL|SESSION)\.(GTID_PURGED|SQL_LOG_BIN)/;
      if (/^(?:INSERT INTO|REPLACE INTO) `([^`]+)`/ && $1 =~ $acct) { $dropped++; $_ = ""; }
      s{/\*!5001[37] DEFINER=`[^`]*`\@`[^`]*`\s*\*/}{}g;
      s{DEFINER=`[^`]*`\@`[^`]*`\s*}{}g;
      END { print STDERR "normalize-dump: $tables CREATE TABLE statements; $dropped account-table INSERT statements dropped ($ENV{ACCOUNT_TABLES})\n"; }
    ' \
  | compress > "$tmp"
mv "$tmp" "$out"

# Sanity: a dump without tables is not a dump. (grep -c, not -q: with pipefail an early
# grep exit would make the decompressor fail with SIGPIPE.)
tables="$(decompress "$out" | grep -c '^CREATE TABLE ' || true)"
if [ "${tables:-0}" -eq 0 ]; then
  echo "normalize-dump: no CREATE TABLE found in $in; is this a MySQL dump?" >&2
  rm -f "$out"
  exit 1
fi
echo "normalize-dump: wrote $out ($(du -h "$out" | cut -f1))"
