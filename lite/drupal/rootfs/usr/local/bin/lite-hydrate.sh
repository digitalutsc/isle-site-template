#!/command/with-contenv bash
# shellcheck shell=bash
#
# Point an Islandora Lite site at the services of this stack. Re-runnable: called by the
# first-start install (Lite site and production-site replicas) and by `make lite-hydrate`
# / `make site-finalize` (for example after switching URI_SCHEME to https). Mirrors
# isle-dc's update-config-from-lite-environment plus the config-set calls from
# islandora-lite-site/scripts/install/install.sh.
#
# Every config:set is guarded by a config:get, so a site that lacks a module (no
# triplestore_indexer, no media_fits, ...) is left alone instead of aborting or getting
# a junk config object. The same values are also enforced at runtime by
# /etc/islandora/lite/settings.lite.php; this makes the stored config match.
set -e

# shellcheck disable=SC1091
source /etc/islandora/utilities.sh

readonly SITE="default"
readonly URI="${DRUSH_OPTIONS_URI}"
readonly BLAZEGRAPH_URL="http://${DRUPAL_DEFAULT_TRIPLESTORE_HOST:-blazegraph}:${DRUPAL_DEFAULT_TRIPLESTORE_PORT:-8080}/bigdata"
readonly FITS_URL="http://${DRUPAL_DEFAULT_FITS_HOST:-fits}:${DRUPAL_DEFAULT_FITS_PORT:-8080}/fits/examine"

cd /var/www/drupal

d() { drush --root=/var/www/drupal --uri="${URI}" -y "$@"; }

# set_cfg <config object> <key> <value> [extra drush options]: only if the object exists.
set_cfg() {
  local object="$1" key="$2" value="$3"
  shift 3
  if d config:get "${object}" >/dev/null 2>&1; then
    d config:set "$@" "${object}" "${key}" "${value}" || echo "warn: could not set ${object}:${key}"
  else
    echo "skip: ${object} not present"
  fi
}

# JWT key from the JWT_PRIVATE_KEY secret, Solr backend module, OpenSeadragon/IIIF URLs
# (DRUPAL_DEFAULT_CANTALOUPE_URL). Allowed to fail like in isle-dc.
configure_jwt_module "${SITE}" || true
configure_search_api_solr_module "${SITE}" || true
configure_openseadragon "${SITE}" || true

# Every Solr server that uses the search_api_solr backend (prod sites may name theirs
# differently than default_solr_server).
for server in $(d php:eval 'print implode(PHP_EOL, \Drupal::configFactory()->listAll("search_api.server."));' 2>/dev/null || true); do
  backend="$(d config:get "${server}" backend --format=string 2>/dev/null || true)"
  case "${backend}" in *search_api_solr*) ;; *) continue ;; esac
  set_cfg "${server}" backend_config.connector_config.scheme http
  set_cfg "${server}" backend_config.connector_config.host "${DRUPAL_DEFAULT_SOLR_HOST:-solr}"
  set_cfg "${server}" backend_config.connector_config.port "${DRUPAL_DEFAULT_SOLR_PORT:-8983}"
  set_cfg "${server}" backend_config.connector_config.path /
  set_cfg "${server}" backend_config.connector_config.core "${DRUPAL_DEFAULT_SOLR_CORE:-default}"
done

# Blazegraph (triplestore_indexer talks to it from PHP, so use the internal name).
set_cfg triplestore_indexer.settings server_url "${BLAZEGRAPH_URL}"
set_cfg triplestore_indexer.settings namespace "${DRUPAL_DEFAULT_TRIPLESTORE_NAMESPACE:-islandora}"

# FITS web service (template service "fits").
set_cfg media_fits.fitsconfig fits-method remote
set_cfg media_fits.fitsconfig fits-server-url "${FITS_URL}"

# IIIF / Mirador: manifests come from this site; images from the shared Cantaloupe.
set_cfg islandora_iiif.settings iiif_server "${DRUPAL_DEFAULT_CANTALOUPE_URL}"
set_cfg openseadragon.settings iiif_server "${DRUPAL_DEFAULT_CANTALOUPE_URL}"
set_cfg islandora_mirador.settings iiif_manifest_url "${URI}/node/[node:nid]/manifest"

# advancedqueue_runner paths inside the container.
set_cfg advancedqueue_runner.settings drush_path /var/www/drupal/vendor/drush/drush/drush --input-format=yaml
set_cfg advancedqueue_runner.settings root_path /var/www/drupal --input-format=yaml
set_cfg advancedqueue_runner.settings base_url "${URI}" --input-format=yaml

# Thumbnails for video (ffmpeg baked into the image).
set_cfg media_thumbnails_video.settings ffmpeg /usr/bin/ffmpeg
set_cfg media_thumbnails_video.settings ffprobe /usr/bin/ffprobe

# Site name. Lite dev site: from the compose environment ("Islandora Lite"; upstream
# scripts/ping.sh greps the front page for "Islandora", so `make up` fails without it).
# Production replicas (LITE_SITE_MODE=localize) keep their name unless LITE_SITE_NAME says
# otherwise.
if [ "${LITE_SITE_MODE:-}" = "localize" ]; then
  if [ -n "${LITE_SITE_NAME:-}" ]; then
    set_cfg system.site name "${LITE_SITE_NAME}"
  fi
elif [ -n "${DRUPAL_DEFAULT_NAME:-}" ]; then
  set_cfg system.site name "${DRUPAL_DEFAULT_NAME}"
fi

# On a production database older than this codebase the router rebuild can fail (for
# example Drupal 11.4 adds router.alias in updb); the config writes above already
# happened and `drush updb` rebuilds caches afterwards, so do not abort here.
d cache:rebuild || echo "warn: cache rebuild failed (database schema older than the codebase?); continuing, updb rebuilds it"
