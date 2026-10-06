<?php

/**
 * @file
 * Recreates the user/account tables that were left out of the prod database copy.
 *
 * The prod copy has no users, users_field_data, users_data, user__roles,
 * user__user_picture or shortcut_set_users. Drupal cannot bootstrap a request
 * without them. The table definitions are taken from what Drupal itself stored
 * in key_value (entity.storage_schema.sql) and from the owning modules'
 * hook_schema(), so they match the prod schema exactly. The tables are left
 * empty; `make users-from-isle` fills them from the isle-dc Lite site.
 *
 * Run inside the drupal container, before `drush updb`:
 *   php /opt/lite/scripts/restore-user-tables.php
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$root = '/var/www/drupal/web';
chdir($root);
$autoloader = require $root . '/autoload.php';

$request = Request::create('https://' . getenv('DRUPAL_DEFAULT_SITE_URL') . '/');
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
// Loads modules so their .install files (hook_schema) can be included.
$kernel->preHandle($request);

$database = \Drupal::database();
$schema = $database->schema();
$moduleHandler = \Drupal::moduleHandler();

// 1. Entity tables: merge the base table skeletons with every base/field schema entry.
$specs = [];
foreach (\Drupal::keyValue('entity.storage_schema.sql')->getAll() as $name => $tables) {
  if (!str_starts_with($name, 'user.')) {
    continue;
  }
  foreach ($tables as $table => $spec) {
    foreach ($spec as $key => $value) {
      if (is_array($value) && isset($specs[$table][$key]) && is_array($specs[$table][$key])) {
        $specs[$table][$key] += $value;
      }
      else {
        $specs[$table][$key] = $value;
      }
    }
  }
}

// 2. Plain module tables.
$moduleHandler->loadInclude('user', 'install');
$specs['users_data'] = user_schema()['users_data'];
if ($moduleHandler->moduleExists('shortcut')) {
  $moduleHandler->loadInclude('shortcut', 'install');
  $specs['shortcut_set_users'] = shortcut_schema()['shortcut_set_users'];
}

foreach ($specs as $table => $spec) {
  if ($schema->tableExists($table)) {
    echo "exists:  $table\n";
    continue;
  }
  $schema->createTable($table, $spec);
  echo "created: $table\n";
}
