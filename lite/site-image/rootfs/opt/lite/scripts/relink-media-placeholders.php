<?php

/**
 * @file
 * Re-points every managed file to a generic file of its media type.
 *
 * Production files are never part of a handover. One generic file per (scheme, MIME type)
 * is generated under <scheme>://lite-placeholders/<slug>.<ext>, and every file_managed row
 * is rewritten to ITS OWN path <scheme>://lite-placeholders/<slug>/<fid div 1000>/<fid>.<ext>,
 * a symlink to the generic file. The per-row path matters: Drupal's private file download
 * handler (file_file_download) loads the first file entity with a given URI and checks
 * access on that one only, so files that share one URI would inherit an arbitrary row's
 * access. Symlinks cost no space and have no link-count limit.
 *
 * filename, filemime, status and uid are kept; filesize becomes the generic file's. The
 * original URIs are saved in lite_file_uri_backup (fid, uri, filesize) for reversal.
 *
 * Run inside the drupal container (full bootstrap, as root):
 *   drush php:script /opt/lite/scripts/relink-media-placeholders.php
 * Idempotent: rows already inside lite-placeholders/ get their symlink (re)created on a
 * fresh volume and are re-pointed if their expected path changed.
 */

use Drupal\Core\StreamWrapper\StreamWrapperManager;

require_once __DIR__ . '/lite-placeholders.inc.php';

$database = \Drupal::database();

// Reversible: keep the original uri/filesize per fid.
if (!$database->schema()->tableExists('lite_file_uri_backup')) {
  $database->schema()->createTable('lite_file_uri_backup', [
    'description' => 'Original file_managed.uri before relink-media-placeholders.php (isle-site-lite).',
    'fields' => [
      'fid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      'uri' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
      'filesize' => ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
    ],
    'primary key' => ['fid'],
  ]);
}

// Core ships the generic media icons; keep those rows pointing at real copies.
$iconDir = DRUPAL_ROOT . '/core/modules/media/images/icons';

/**
 * Ensures <base>/lite-placeholders/<slug>/<fid div 1000>/<fid>.<ext> is a symlink to the
 * generic file; returns the row's URI.
 */
$perRow = function (string $scheme, array $gen, int $fid): ?string {
  $base = lite_placeholder_scheme_dir($scheme);
  if ($base === NULL) {
    return NULL;
  }
  $rel = LITE_PLACEHOLDER_DIR . "/{$gen['slug']}/" . intdiv($fid, 1000) . "/{$fid}.{$gen['ext']}";
  $path = "{$base}/{$rel}";
  if (!is_link($path) && !file_exists($path)) {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      echo "WARN: cannot create {$dir}\n";
      return NULL;
    }
    // Relative target: survives a move of the files directory.
    symlink("../../{$gen['slug']}.{$gen['ext']}", $path);
  }
  return "{$scheme}://{$rel}";
};

$relinked = $kept = $repointed = $icons = $skipped = 0;
$byPair = [];
$otherSchemes = [];
$lastFid = 0;
while (TRUE) {
  $rows = $database->query('SELECT fid, uri, filemime, filesize FROM {file_managed} WHERE fid > :fid ORDER BY fid LIMIT 2000', [':fid' => $lastFid])->fetchAll();
  if (!$rows) {
    break;
  }
  foreach ($rows as $row) {
    $lastFid = (int) $row->fid;
    $scheme = StreamWrapperManager::getScheme($row->uri);
    $target = StreamWrapperManager::getTarget($row->uri);
    if (!$scheme || $target === FALSE) {
      $skipped++;
      continue;
    }
    if ($scheme === 'public' && str_starts_with($target, 'media-icons/generic/') && file_exists($iconDir . '/' . basename($target))) {
      $dest = lite_placeholder_scheme_dir('public') . '/' . $target;
      if (!file_exists($dest)) {
        @mkdir(dirname($dest), 0775, TRUE);
        copy($iconDir . '/' . basename($target), $dest);
      }
      $icons++;
      continue;
    }
    $mime = $row->filemime ?: 'application/octet-stream';
    $gen = lite_placeholder_generic($scheme, $mime);
    if ($gen === NULL) {
      $otherSchemes[$scheme] = ($otherSchemes[$scheme] ?? 0) + 1;
      $skipped++;
      continue;
    }
    $newUri = $perRow($scheme, $gen, $lastFid);
    if ($newUri === NULL) {
      $skipped++;
      continue;
    }
    $already = str_starts_with($target, LITE_PLACEHOLDER_DIR . '/');
    if (!$already) {
      $database->merge('lite_file_uri_backup')
        ->key('fid', $row->fid)
        ->fields(['uri' => $row->uri, 'filesize' => (int) $row->filesize])
        ->execute();
    }
    if ($row->uri !== $newUri || (int) $row->filesize !== $gen['size']) {
      $database->update('file_managed')
        ->fields(['uri' => $newUri, 'filesize' => $gen['size']])
        ->condition('fid', $row->fid)
        ->execute();
      if ($already) {
        $repointed++;
      }
    }
    if ($already) {
      $kept++;
    }
    else {
      $relinked++;
      $pair = "{$scheme}://  {$mime}";
      $byPair[$pair] = ($byPair[$pair] ?? 0) + 1;
    }
  }
  echo "... up to fid {$lastFid}: relinked {$relinked}, already generic {$kept} ({$repointed} re-pointed), icons {$icons}, skipped {$skipped}\n";
}

lite_placeholder_chown();

// Derivatives of the old files are meaningless now; the file entity cache too.
\Drupal::service('cache_tags.invalidator')->invalidateTags(['file_list']);
foreach (\Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple() as $style) {
  $style->flush();
}

echo "done: relinked {$relinked}, already generic {$kept} ({$repointed} re-pointed to their per-row path), media icons {$icons}, skipped {$skipped}\n";
foreach ($byPair as $pair => $n) {
  echo "  {$n}\t{$pair}\n";
}
foreach ($otherSchemes as $scheme => $n) {
  echo "  NOTE: {$n} rows use scheme {$scheme}:// (not a local wrapper), left untouched\n";
}
echo "originals kept in table lite_file_uri_backup (fid, uri, filesize)\n";
