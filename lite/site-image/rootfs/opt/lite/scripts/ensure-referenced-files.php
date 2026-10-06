<?php

/**
 * @file
 * Creates generic files for paths referenced outside file_managed.
 *
 * Themes and content point at files that are not managed file entities: the theme logo
 * (public://logo.svg in config), inline images in text fields (/sites/default/files/inline-images/...),
 * CKEditor uploads, etc. Those paths are collected from the active configuration and from
 * every text field of every entity type, and a generic file of the matching type is written
 * at each missing path (real files from site-files/ are never overwritten).
 *
 * Run inside the drupal container (full bootstrap, as root):
 *   drush php:script /opt/lite/scripts/ensure-referenced-files.php
 */

require_once __DIR__ . '/lite-placeholders.inc.php';

$database = \Drupal::database();
$publicDir = lite_placeholder_scheme_dir('public');
$publicUrlPrefix = '/' . ltrim(\Drupal::service('stream_wrapper_manager')->getViaScheme('public')->getDirectoryPath(), '/');
$publicUrlPrefix = rtrim($publicUrlPrefix, '/') . '/';   // e.g. /sites/default/files/
$extensions = 'png|jpe?g|gif|svg|webp|tiff?|jp2|bmp|ico|pdf|mp3|wav|ogg|mp4|mov|webm|mkv|txt|csv|vtt|xml|json';

$paths = [];
$add = function (string $raw) use (&$paths) {
  $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
  $raw = preg_replace('/[?#].*$/', '', $raw);
  $raw = rawurldecode($raw);
  $raw = trim($raw);
  if ($raw === '' || str_contains($raw, '..')) {
    return;
  }
  $paths[$raw] = TRUE;
};

$collect = function (string $text) use ($add, $publicUrlPrefix, $extensions) {
  if (preg_match_all('#public://([^"\'\s<>)\]]+\.(?:' . $extensions . '))#i', $text, $m)) {
    foreach ($m[1] as $p) {
      $add($p);
    }
  }
  $prefix = preg_quote($publicUrlPrefix, '#');
  if (preg_match_all('#(?:https?://[^/"\'\s]+)?' . $prefix . '((?!styles/)[^"\'\s<>)\]]+\.(?:' . $extensions . '))#i', $text, $m)) {
    foreach ($m[1] as $p) {
      $add($p);
    }
  }
};

// 1. Active configuration.
foreach ($database->query('SELECT name, data FROM {config}') as $row) {
  $collect((string) $row->data);
}

// 2. Every text-ish field of every entity type (base and revision tables).
$fieldMap = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('text_long')
  + \Drupal::service('entity_field.manager')->getFieldMapByFieldType('text_with_summary')
  + \Drupal::service('entity_field.manager')->getFieldMapByFieldType('string_long')
  + \Drupal::service('entity_field.manager')->getFieldMapByFieldType('text');
$etm = \Drupal::entityTypeManager();
$scanned = 0;
foreach (['text_long', 'text_with_summary', 'string_long', 'text'] as $type) {
  foreach (\Drupal::service('entity_field.manager')->getFieldMapByFieldType($type) as $entityTypeId => $fields) {
    $storage = $etm->getStorage($entityTypeId);
    if (!$storage instanceof \Drupal\Core\Entity\Sql\SqlContentEntityStorage) {
      continue;
    }
    $mapping = $storage->getTableMapping();
    foreach ($fields as $fieldName => $info) {
      try {
        $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions($entityTypeId);
        if (!isset($definitions[$fieldName])) {
          continue;
        }
        $table = $mapping->getFieldTableName($fieldName);
        $column = $mapping->getFieldColumnName($definitions[$fieldName], 'value');
      }
      catch (\Throwable $e) {
        continue;
      }
      if (!$database->schema()->tableExists($table) || !$database->schema()->fieldExists($table, $column)) {
        continue;
      }
      $like = $database->escapeLike($publicUrlPrefix);
      $query = $database->select($table, 't')->fields('t', [$column]);
      $or = $query->orConditionGroup()
        ->condition($column, '%' . $like . '%', 'LIKE')
        ->condition($column, '%public://%', 'LIKE');
      $query->condition($or);
      foreach ($query->execute() as $r) {
        $collect((string) $r->{$column});
        $scanned++;
      }
    }
  }
}

$created = $existing = $failed = 0;
foreach (array_keys($paths) as $rel) {
  $path = $publicDir . '/' . $rel;
  if (file_exists($path)) {
    $existing++;
    continue;
  }
  $ext = pathinfo($rel, PATHINFO_EXTENSION);
  $mime = lite_placeholder_mime_for_extension($ext);
  $gen = lite_placeholder_generic('public', $mime);
  if ($gen === NULL) {
    $failed++;
    continue;
  }
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
    echo "WARN: cannot create {$dir}\n";
    $failed++;
    continue;
  }
  // A real copy (not a symlink): these are few, and some are fetched by path by nginx.
  if (copy($gen['path'], $path)) {
    $created++;
    echo "created public://{$rel} ({$mime})\n";
  }
  else {
    $failed++;
  }
}

lite_placeholder_chown();
echo "done: {$created} created, {$existing} already present, {$failed} failed (from " . count($paths) . " referenced paths; {$scanned} text values scanned)\n";
