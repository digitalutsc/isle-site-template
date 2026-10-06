#!/command/with-contenv bash
# shellcheck shell=bash
#
# Point the Islandora Lite site at the services of this stack. Re-runnable:
# called by the first-start install and by `make lite-hydrate` (for example after
# switching URI_SCHEME to https). Mirrors isle-dc's update-config-from-lite-environment
# plus the config-set calls from islandora-lite-site/scripts/install/install.sh.
set -e

# shellcheck disable=SC1091
source /etc/islandora/utilities.sh

readonly SITE="default"
readonly URI="${DRUSH_OPTIONS_URI}"
readonly BLAZEGRAPH_URL="http://${DRUPAL_DEFAULT_TRIPLESTORE_HOST}:${DRUPAL_DEFAULT_TRIPLESTORE_PORT}/bigdata"

cd /var/www/drupal

d() { drush --root=/var/www/drupal --uri="${URI}" -y "$@"; }

# JWT key from the JWT_PRIVATE_KEY secret, Solr backend, OpenSeadragon/IIIF URLs
# (DRUPAL_DEFAULT_CANTALOUPE_URL). Allowed to fail like in isle-dc.
configure_jwt_module "${SITE}" || true
configure_search_api_solr_module "${SITE}" || true
configure_openseadragon "${SITE}" || true

# Solr server: the exported config already says http://solr:8983 core ISLANDORA; enforce it.
d config:set search_api.server.default_solr_server backend_config.connector_config.scheme http
d config:set search_api.server.default_solr_server backend_config.connector_config.host "${DRUPAL_DEFAULT_SOLR_HOST}"
d config:set search_api.server.default_solr_server backend_config.connector_config.port "${DRUPAL_DEFAULT_SOLR_PORT}"
d config:set search_api.server.default_solr_server backend_config.connector_config.core "${DRUPAL_DEFAULT_SOLR_CORE}"

# Blazegraph (triplestore_indexer talks to it from PHP, so use the internal name).
d config:set triplestore_indexer.settings server_url "${BLAZEGRAPH_URL}"
d config:set triplestore_indexer.settings namespace "${DRUPAL_DEFAULT_TRIPLESTORE_NAMESPACE}"

# FITS web service (template service "fits").
d config:set media_fits.fitsconfig fits-method remote || true
d config:set media_fits.fitsconfig fits-server-url "http://${DRUPAL_DEFAULT_FITS_HOST:-fits}:${DRUPAL_DEFAULT_FITS_PORT:-8080}/fits/examine" || true

# Mirador manifests come from this site.
d config:set islandora_mirador.settings iiif_manifest_url "${URI}/node/[node:nid]/manifest" || true

# advancedqueue_runner paths inside the container.
d config:set --input-format=yaml advancedqueue_runner.settings drush_path /var/www/drupal/vendor/drush/drush/drush || true
d config:set --input-format=yaml advancedqueue_runner.settings root_path /var/www/drupal || true

# Site name from the compose environment ("Islandora Lite"). The exported config says
# "Default", and upstream scripts/ping.sh greps the front page for "Islandora" to decide
# that the site is up, so `make up` fails its final check without this.
if [ -n "${DRUPAL_DEFAULT_NAME:-}" ]; then
  d config:set system.site name "${DRUPAL_DEFAULT_NAME}" || true
fi

# Thumbnails for video (ffmpeg baked into the image).
d config:set media_thumbnails_video.settings ffmpeg /usr/bin/ffmpeg || true
d config:set media_thumbnails_video.settings ffprobe /usr/bin/ffprobe || true

d cache:rebuild
