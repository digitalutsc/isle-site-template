#!/command/with-contenv bash
# shellcheck shell=bash
#
# First start of a production-site replica (localize mode). Replaces the Lite install.sh
# in the site image. Same skeleton and markers as upstream's install.sh so that
# `make up`, `make lite-ping` and /installed behave the same.
#
# The database is seeded by the db-<site> MySQL container from lite/sites/<site>/db/, so
# nothing is installed here. What runs once is the localization (lite-site-finalize.sh):
# re-point services, JWT key, Solr core, orphaned facets, updb, placeholders. The gate is
# the state key "lite.localized", checked with plain SQL (no drush bootstrap on restarts).
set -e

# shellcheck disable=SC1091
source /etc/islandora/utilities.sh

readonly SITE="default"

function triplestore_enabled {
    grep -qE '^[[:space:]]+triplestore_indexer:[[:space:]]*0' /var/www/drupal/config/sync/core.extension.yml 2>/dev/null
}

function install {
    wait_for_service "${SITE}" db
    wait_for_service "${SITE}" solr
    wait_for_service "${SITE}" fits
    if triplestore_enabled; then
        wait_for_service "${SITE}" triplestore
    fi

    # Accounts: never the production ones. normalize-dump.sh dropped their rows; the
    # finalize step copies the Lite dev site's accounts in (lite-accounts-from-lite.sh).
    lite-site-finalize.sh
}

function sql_scalar {
    execute-sql-file.sh <(cat) -- -N 2>/dev/null || true
}

function database_seeded {
    local count
    count=$(sql_scalar <<-EOF
SELECT COUNT(DISTINCT table_name)
FROM information_schema.columns
WHERE table_schema = '${DRUPAL_DEFAULT_DB_NAME}';
EOF
    )
    [[ "${count:-0}" -ne 0 ]]
}

# Localization done once: state key written by lite-site-finalize.sh.
function localized {
    local count
    count=$(sql_scalar <<-EOF
SELECT COUNT(*) FROM \`${DRUPAL_DEFAULT_DB_NAME}\`.key_value
WHERE collection = 'state' AND name = 'lite.localized';
EOF
    )
    [[ "${count:-0}" -ne 0 ]]
}

# Required even if not installing.
function setup() {
    local site drupal_root subdir site_directory public_files_directory private_files_directory twig_cache_directory
    site="${1}"
    shift

    drupal_root=/var/www/drupal/web
    subdir=$(drupal_site_env "${site}" "SUBDIR")
    site_directory="${drupal_root}/sites/${subdir}"
    public_files_directory="${site_directory}/files"
    private_files_directory="/var/www/drupal/private"
    twig_cache_directory="${private_files_directory}/php"

    mkdir -p "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
    chown nginx:nginx "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
    chmod ug+rw "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
}

function drush_cache_setup {
    mkdir -p /tmp/drush-/cache
    chmod a+rwx /tmp/drush-/cache
}

# External processes can look for `/installed` to check if installation is completed.
function finished {
    touch /installed
    cat <<-EOT


#####################
# Install Completed #
#####################
EOT
}

function main() {
    cd /var/www/drupal
    drush_cache_setup
    for_all_sites setup

    wait_for_service "${SITE}" db
    if ! database_seeded; then
        echo "ERROR: database '${DRUPAL_DEFAULT_DB_NAME}' is empty. The db container seeds it from"
        echo "       lite/sites/<site>/db/<site>.sql.xz on its first start: run 'make site-intake' and"
        echo "       'make site-reset' if the data volume was created before the dump existed."
        exit 1
    fi

    if localized; then
        echo "Already Installed"
    else
        echo "Localizing"
        install
    fi
    finished
}
main
