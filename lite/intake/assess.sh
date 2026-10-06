#!/usr/bin/env bash
#
# assess.sh <site> <code dir> <stage dir> <normalized dump>
#
# Read-only report about a handover, appended to lite/sites/<site>/NOTES.md by intake.sh.
# Trimmed from the menus skill template: only what matters on isle-site-lite.
set -uo pipefail

site="${1:?}"; code="${2:?}"; stage="${3:?}"; dump="${4:-}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
sync="$stage/config/sync"
lock="lite/site/composer.lock"

h() { echo; echo "### $*"; echo; }

h "Code"
if [ -f "$code/composer.json" ]; then
  python3 - "$code/composer.json" <<'EOF'
import json, sys
c = json.load(open(sys.argv[1]))
r = c.get("require", {})
print(f"- composer: {c.get('name')} php {r.get('php')} core {r.get('drupal/core-recommended') or r.get('drupal/core')} drush {r.get('drush/drush')}")
EOF
fi
echo "- composer.lock in site repo: $([ -f "$code/composer.lock" ] && echo yes || echo no) (ignored: the Lite composer set is used)"
echo "- config/sync files: $(ls "$sync" | wc -l | tr -d ' ')"
echo "- profile: $(grep -E '^profile:' "$sync/core.extension.yml" | cut -d: -f2 | tr -d ' ')"
echo "- site name in config: $(grep -E '^name:' "$sync/system.site.yml" 2>/dev/null | cut -d: -f2-)"
echo "- themes: default=$(grep -E '^default:' "$sync/system.theme.yml" | cut -d: -f2 | tr -d ' ') admin=$(grep -E '^admin:' "$sync/system.theme.yml" | cut -d: -f2 | tr -d ' ') staged=$(ls "$stage/web/themes" 2>/dev/null | tr '\n' ' ')"

h "Enabled modules vs the Lite composer set"
modules="$(sed -n '/^module:/,/^theme:/p' "$sync/core.extension.yml" | grep -E '^[[:space:]]+[a-z0-9_]+:' | sed -E 's/^[[:space:]]+([a-z0-9_]+):.*/\1/')"
echo "- enabled modules: $(echo "$modules" | wc -l | tr -d ' ')"
if [ -f "$lock" ]; then
  python3 - "$lock" "$stage" <<'EOF' "$modules"
import json, os, sys
lock, stage, modules = sys.argv[1], sys.argv[2], sys.argv[3].split()
pkgs = {p["name"].split("/")[-1] for p in json.load(open(lock))["packages"]}
core = set()
# Core modules cannot be listed without the codebase; treat names without a package as "check".
custom = set()
for root in (os.path.join(stage, "web/modules/custom"),):
    if os.path.isdir(root):
        for d, _, files in os.walk(root):
            for f in files:
                if f.endswith(".info.yml"):
                    custom.add(f[:-9])
unknown = [m for m in modules if m not in pkgs and m not in custom]
print(f"- provided by a Lite composer package: {len(modules) - len(unknown)}; custom (staged): {len([m for m in modules if m in custom])}")
print("- not matched to a package by name (core modules and sub-modules are expected here; anything else is a real gap):")
print("  " + " ".join(unknown))
EOF
fi
for m in search_api_solr facets triplestore_indexer media_fits jwt group islandora_group memcache; do
  echo "$modules" | grep -qx "$m" && echo "- $m: enabled" || echo "- $m: not enabled"
done

h "Services in config"
for f in "$sync"/search_api.server.*.yml; do
  [ -f "$f" ] || continue
  echo "- $(basename "$f" .yml): backend=$(grep -E '^backend:' "$f" | cut -d: -f2 | tr -d ' ') host=$(grep -E '^[[:space:]]+host:' "$f" | head -1 | cut -d: -f2 | tr -d ' ') core=$(grep -E '^[[:space:]]+core:' "$f" | head -1 | cut -d: -f2 | tr -d ' ')"
done
echo "- search_api indexes: $(ls "$sync"/search_api.index.*.yml 2>/dev/null | wc -l | tr -d ' '); facets: $(ls "$sync"/facets.facet.*.yml 2>/dev/null | wc -l | tr -d ' ')"
for f in islandora_iiif.settings openseadragon.settings islandora_mirador.settings media_fits.fitsconfig triplestore_indexer.settings; do
  [ -f "$sync/$f.yml" ] && echo "- $f: $(grep -E 'iiif_server|iiif_manifest_url|fits-server-url|server_url' "$sync/$f.yml" | tr '\n' ' ')"
done

h "Hostnames and files named in config"
echo "- hostnames (count): $(grep -ohE 'https?://[A-Za-z0-9.-]+' "$sync"/*.yml | sed -E 's#https?://##' | sort | uniq -c | sort -rn | head -8 | awk '{printf "%s(%s) ", $2, $1}')"
echo "- public:// files named in config (put real copies into site-files/ if they matter, e.g. the theme logo):"
grep -ohE "public://[^'\" ]+" "$sync"/*.yml | sort -u | sed 's/^/  /' | head -20

h "Database"
if [ -n "$dump" ] && [ -s "$dump" ]; then
  dc() { case "$dump" in *.xz) xz -dc "$dump";; *.gz) gzip -dc "$dump";; *) cat "$dump";; esac; }
  echo "- normalized dump: $dump ($(du -h "$dump" | cut -f1))"
  echo "- tables: $(dc | grep -c '^CREATE TABLE ')"
  n_users="$(dc | grep -c 'INSERT INTO `users_field_data`' || true)"
  n_files="$(dc | grep -c 'INSERT INTO `file_managed`' || true)"
  echo "- accounts: $([ "${n_users:-0}" -gt 0 ] && echo 'users_field_data has rows' || echo 'NO rows in users_field_data (run make site-users-from-lite after the first start)')"
  echo "- file_managed rows present: $([ "${n_files:-0}" -gt 0 ] && echo yes || echo no) (placeholders relink them to generic files at first start)"
else
  echo "- no normalized dump yet"
fi
if ls "lite/sites/$site/db/incoming"/recovery-*/summary.txt >/dev/null 2>&1; then
  echo "- .ibd recovery: $(cat "$(ls -t "lite/sites/$site/db/incoming"/recovery-*/summary.txt | head -1)" | tr '\n' ';')"
fi
