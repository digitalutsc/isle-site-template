<?php

/**
 * @file
 * Creates a generic placeholder file for every file_managed row whose file is missing.
 *
 * The prod files directory is not part of the replica (too big), but the
 * recovered file_managed rows keep the real URIs and MIME types. One template
 * is generated per MIME type (ImageMagick / ffmpeg / plain text) and copied to
 * each missing path, so media, image styles and IIIF requests resolve.
 *
 * Run inside the drupal container (full bootstrap, as root):
 *   drush php:script /opt/lite/scripts/make-placeholder-files.php
 * Re-running only fills in files that are still missing.
 */

use Drupal\Core\File\FileExists;

$fileSystem = \Drupal::service('file_system');
$database = \Drupal::database();
$templateDir = $fileSystem->realpath('temporary://') . '/lite-placeholders-tmp';
if (!is_dir($templateDir)) {
  mkdir($templateDir, 0775, TRUE);
}

$label = 'PLACEHOLDER - local replica';
// DejaVu is installed in the image (ttf-dejavu); ImageMagick has no default font otherwise.
$imageArgs = "-size 1200x900 xc:'#b8b8b8' -fill '#444' -gravity center -font DejaVu-Sans -pointsize 48 -annotate 0 '{$label}'";

/**
 * Returns the shell command that writes the template for $mime to $out, or NULL for a text stub.
 *
 * The image's ImageMagick has no TIFF, JPEG 2000 or PDF coders; ffmpeg encodes
 * TIFF and JPEG 2000 from a PNG rendered by ImageMagick.
 */
function placeholder_command(string $mime, string $out, string $imageArgs): ?string {
  $viaFfmpeg = fn(string $codecArgs) => "convert {$imageArgs} png:{$out}.png && ffmpeg -loglevel error -y -i {$out}.png {$codecArgs} {$out} && rm -f {$out}.png";
  return match (TRUE) {
    $mime === 'image/jpeg' => "convert {$imageArgs} -quality 85 jpg:{$out}",
    $mime === 'image/png' => "convert {$imageArgs} png:{$out}",
    $mime === 'image/gif' => "convert {$imageArgs} gif:{$out}",
    $mime === 'image/webp' => "convert {$imageArgs} webp:{$out}",
    $mime === 'image/tiff' => $viaFfmpeg('-pix_fmt rgb24 -c:v tiff'),
    // rgb24: Cantaloupe's Grok decoder rejects the 16-bit grayscale JP2 ffmpeg writes from a gray PNG.
    $mime === 'image/jp2' => $viaFfmpeg('-pix_fmt rgb24 -c:v jpeg2000 -format jp2'),
    str_starts_with($mime, 'audio/') => "ffmpeg -loglevel error -y -f lavfi -i anullsrc=r=44100:cl=mono -t 2 {$out}",
    str_starts_with($mime, 'video/') => "ffmpeg -loglevel error -y -f lavfi -i color=c=gray:s=640x480:d=2 -pix_fmt yuv420p {$out}",
    default => NULL,
  };
}

$extensions = [
  'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
  'image/tiff' => 'tif', 'image/jp2' => 'jp2', 'application/pdf' => 'pdf',
  'audio/mpeg' => 'mp3', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a',
  'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
];

// drush php:script includes this file inside a method, so no globals: closures instead.
$templates = [];
$templateFor = function (string $mime) use (&$templates, $templateDir, $imageArgs, $extensions, $label): string {
  if (isset($templates[$mime])) {
    return $templates[$mime];
  }
  $ext = $extensions[$mime] ?? 'txt';
  $out = $templateDir . '/placeholder.' . $ext;
  if (!file_exists($out)) {
    $cmd = placeholder_command($mime, escapeshellarg($out), $imageArgs);
    if ($cmd !== NULL) {
      exec($cmd . ' 2>&1', $output, $status);
      if ($status !== 0 || !file_exists($out)) {
        echo "WARN: could not build template for {$mime} (" . implode(' ', $output) . "); using text stub\n";
      }
    }
    if (!file_exists($out)) {
      $body = str_starts_with($mime, 'application/xml') || str_starts_with($mime, 'text/xml')
        ? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<placeholder>{$label}</placeholder>\n"
        : "{$label}\n";
      file_put_contents($out, $body);
    }
  }
  return $templates[$mime] = $out;
};

// Core ships the generic media icons; copy the real ones instead of a placeholder.
$iconDir = DRUPAL_ROOT . '/core/modules/media/images/icons';

// realpath() returns FALSE for files that do not exist yet, so build the path
// from the scheme's directory instead.
$wrapperManager = \Drupal::service('stream_wrapper_manager');
$schemeDirs = [];
$localPath = function (string $uri) use ($wrapperManager, &$schemeDirs): ?string {
  $scheme = \Drupal\Core\StreamWrapper\StreamWrapperManager::getScheme($uri);
  if (!$scheme) {
    return NULL;
  }
  if (!array_key_exists($scheme, $schemeDirs)) {
    $wrapper = $wrapperManager->getViaScheme($scheme);
    $schemeDirs[$scheme] = $wrapper instanceof \Drupal\Core\StreamWrapper\LocalStream ? $wrapper->getDirectoryPath() : NULL;
  }
  if ($schemeDirs[$scheme] === NULL) {
    return NULL;
  }
  $dir = $schemeDirs[$scheme];
  if (!str_starts_with($dir, '/')) {
    $dir = DRUPAL_ROOT . '/' . $dir;
  }
  return $dir . '/' . \Drupal\Core\StreamWrapper\StreamWrapperManager::getTarget($uri);
};

$created = $existing = $failed = 0;
$byMime = [];
$lastFid = 0;
while (TRUE) {
  $rows = $database->query('SELECT fid, uri, filemime FROM {file_managed} WHERE fid > :fid ORDER BY fid LIMIT 2000', [':fid' => $lastFid])->fetchAll();
  if (!$rows) {
    break;
  }
  foreach ($rows as $row) {
    $lastFid = $row->fid;
    $path = $localPath($row->uri);
    if ($path === NULL) {
      $failed++;
      echo "WARN: unresolvable uri {$row->uri}\n";
      continue;
    }
    if (file_exists($path)) {
      $existing++;
      continue;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      $failed++;
      echo "WARN: cannot create {$dir}\n";
      continue;
    }
    $source = NULL;
    if (str_starts_with($row->uri, 'public://media-icons/generic/') && file_exists($iconDir . '/' . basename($path))) {
      $source = $iconDir . '/' . basename($path);
    }
    $source ??= $templateFor($row->filemime);
    if (copy($source, $path)) {
      $created++;
      $byMime[$row->filemime] = ($byMime[$row->filemime] ?? 0) + 1;
    }
    else {
      $failed++;
    }
  }
  echo "... up to fid {$lastFid}: created {$created}, existing {$existing}, failed {$failed}\n";
}

foreach (['public://', 'private://'] as $scheme) {
  $real = $fileSystem->realpath($scheme);
  if ($real) {
    exec('chown -R nginx:nginx ' . escapeshellarg($real));
  }
}

echo "done: created {$created}, already present {$existing}, failed {$failed}\n";
foreach ($byMime as $mime => $n) {
  echo "  {$n}\t{$mime}\n";
}
