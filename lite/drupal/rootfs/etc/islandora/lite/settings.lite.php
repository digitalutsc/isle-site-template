<?php

/**
 * @file
 * Islandora Lite: settings derived from the container environment.
 *
 * Included from web/sites/default/settings.php (appended by lite/drupal/Dockerfile),
 * so it wins over the scaffolded defaults and over the Lite site's own append
 * (assets/patches/default_settings.txt). Mirrors the upstream isle-site-template
 * starter-site snippet (drupal/rootfs/var/www/drupal/assets/patches/default_settings.txt)
 * minus Fedora/ActiveMQ, so that a recreated container (make down / make up) keeps
 * working: nothing written by `drush si` into the container layer is needed.
 */

// Let Drush use all the memory available.
if (PHP_SAPI === 'cli') {
  ini_set('memory_limit', '-1');
}

// Required when running Drupal behind a reverse proxy (Traefik).
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'];
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT;

// Private files on the drupal-private-files volume (upstream path), not under the web root.
$settings['file_private_path'] = '/var/www/drupal/private';

// Exported configuration shipped with the site.
$settings['config_sync_directory'] = '/var/www/drupal/config/sync';

// Container environment (s6 exposes env vars and secrets as files here).
$lite_env_path = '/var/run/s6/container_environment/';
$lite_env = static function (string $name, ?string $default = NULL) use ($lite_env_path): ?string {
  $file = $lite_env_path . $name;
  return file_exists($file) ? trim((string) file_get_contents($file)) : $default;
};

// Services of this stack. These override the exported config at runtime; `drush cex`
// still exports the committed values.
$lite_cantaloupe = $lite_env('DRUPAL_DEFAULT_CANTALOUPE_URL');
if ($lite_cantaloupe !== NULL) {
  $config['islandora_iiif.settings']['iiif_server'] = $lite_cantaloupe;
  $config['openseadragon.settings']['iiif_server'] = $lite_cantaloupe;
}
$lite_site_url = $lite_env('DRUSH_OPTIONS_URI') ?? $lite_env('DRUPAL_DEFAULT_SITE_URL');
if ($lite_site_url !== NULL) {
  $config['islandora_mirador.settings']['iiif_manifest_url'] = rtrim($lite_site_url, '/') . '/node/[node:nid]/manifest';
}
$config['search_api.server.default_solr_server']['backend_config']['connector_config']['scheme'] = 'http';
$config['search_api.server.default_solr_server']['backend_config']['connector_config']['host'] = $lite_env('DRUPAL_DEFAULT_SOLR_HOST', 'solr');
$config['search_api.server.default_solr_server']['backend_config']['connector_config']['port'] = $lite_env('DRUPAL_DEFAULT_SOLR_PORT', '8983');
$config['search_api.server.default_solr_server']['backend_config']['connector_config']['core'] = $lite_env('DRUPAL_DEFAULT_SOLR_CORE', 'default');
$config['triplestore_indexer.settings']['server_url'] = 'http://' . $lite_env('DRUPAL_DEFAULT_TRIPLESTORE_HOST', 'blazegraph') . ':' . $lite_env('DRUPAL_DEFAULT_TRIPLESTORE_PORT', '8080') . '/bigdata';
$config['triplestore_indexer.settings']['namespace'] = $lite_env('DRUPAL_DEFAULT_TRIPLESTORE_NAMESPACE', 'islandora');
// FITS web service (template service "fits"; media_fits calls it directly).
$config['media_fits.fitsconfig']['fits-method'] = 'remote';
$config['media_fits.fitsconfig']['fits-server-url'] = 'http://' . $lite_env('DRUPAL_DEFAULT_FITS_HOST', 'fits') . ':' . $lite_env('DRUPAL_DEFAULT_FITS_PORT', '8080') . '/fits/examine';
$config['advancedqueue_runner.settings']['drush_path'] = '/var/www/drupal/vendor/drush/drush/drush';
$config['advancedqueue_runner.settings']['root_path'] = '/var/www/drupal';
$config['media_thumbnails_video.settings']['ffmpeg'] = '/usr/bin/ffmpeg';
$config['media_thumbnails_video.settings']['ffprobe'] = '/usr/bin/ffprobe';

// JWT private key as provided by the image (configure_jwt_module imports the key config).
if (file_exists('/opt/keys/jwt/private.key')) {
  $config['key.key.islandora_rsa_key']['key_provider_settings']['file_location'] = '/opt/keys/jwt/private.key';
}

// Site-wide settings from the environment.
$lite_salt = $lite_env('DRUPAL_DEFAULT_SALT');
if ($lite_salt !== NULL && $lite_salt !== '') {
  $settings['hash_salt'] = $lite_salt;
}
$lite_domain = $lite_env('DRUPAL_DEFAULT_SITE_URL');
if ($lite_domain !== NULL && $lite_domain !== '') {
  $settings['trusted_host_patterns'] = ['^' . preg_quote(preg_replace('#^https?://#', '', $lite_domain), '#') . '$'];
}

// Database from the environment (same source install_site / drush si uses).
$databases['default']['default'] = [
  'database' => $lite_env('DRUPAL_DEFAULT_DB_NAME', 'drupal_default'),
  'username' => $lite_env('DRUPAL_DEFAULT_DB_USER', 'drupal_default'),
  'password' => $lite_env('DRUPAL_DEFAULT_DB_PASSWORD', ''),
  'host' => $lite_env('DB_MYSQL_HOST', 'mariadb'),
  'port' => $lite_env('DB_MYSQL_PORT', '3306'),
  'prefix' => '',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
];

// Twig cache outside the web root.
$settings['php_storage']['twig']['directory'] = $settings['file_private_path'] . '/php';
if (!empty($settings['hash_salt'])) {
  $settings['php_storage']['twig']['secret'] = $settings['hash_salt'];
}

unset($lite_env_path, $lite_env, $lite_cantaloupe, $lite_site_url, $lite_salt, $lite_domain);
