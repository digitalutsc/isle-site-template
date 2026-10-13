<?php

/**
 * @file
 * Shared helpers for generic placeholder files (isle-site-lite production replicas).
 *
 * Included by relink-media-placeholders.php and ensure-referenced-files.php (both run with
 * `drush php:script`, full bootstrap, as root). Files are never part of a handover; one
 * generic file per (scheme, MIME type) is generated under <scheme>://lite-placeholders/.
 */

use Drupal\Core\StreamWrapper\LocalStream;

const LITE_PLACEHOLDER_DIR = 'lite-placeholders';
const LITE_PLACEHOLDER_LABEL = 'PLACEHOLDER - local replica';

/**
 * Shell command that writes the generic file for $mime to $out, or NULL for a text stub.
 *
 * The image's ImageMagick has no TIFF, JPEG 2000 or PDF coders; ffmpeg encodes TIFF and
 * JPEG 2000 from a PNG rendered by ImageMagick (rgb24: Cantaloupe's Grok decoder rejects
 * 16-bit grayscale JP2).
 */
function lite_placeholder_command(string $mime, string $out): ?string {
  $label = LITE_PLACEHOLDER_LABEL;
  // DejaVu is installed in the image (ttf-dejavu); ImageMagick has no default font otherwise.
  $imageArgs = "-size 1200x900 xc:'#b8b8b8' -fill '#444' -gravity center -font DejaVu-Sans -pointsize 48 -annotate 0 '{$label}'";
  $viaFfmpeg = fn(string $codecArgs) => "convert {$imageArgs} png:{$out}.png && ffmpeg -loglevel error -y -i {$out}.png {$codecArgs} {$out} && rm -f {$out}.png";
  return match (TRUE) {
    $mime === 'image/jpeg', $mime === 'image/jpg', $mime === 'image/pjpeg' => "convert {$imageArgs} -quality 85 jpg:{$out}",
    $mime === 'image/png' => "convert {$imageArgs} png:{$out}",
    $mime === 'image/gif' => "convert {$imageArgs} gif:{$out}",
    $mime === 'image/webp' => "convert {$imageArgs} webp:{$out}",
    $mime === 'image/tiff' => $viaFfmpeg('-pix_fmt rgb24 -c:v tiff'),
    $mime === 'image/jp2', $mime === 'image/jpx', $mime === 'image/jpm' => $viaFfmpeg('-pix_fmt rgb24 -c:v jpeg2000 -format jp2'),
    $mime === 'image/svg+xml' => NULL,
    str_starts_with($mime, 'image/') => "convert {$imageArgs} png:{$out}",
    str_starts_with($mime, 'audio/') => "ffmpeg -loglevel error -y -f lavfi -i anullsrc=r=44100:cl=mono -t 2 {$out}",
    str_starts_with($mime, 'video/') => "ffmpeg -loglevel error -y -f lavfi -i color=c=gray:s=640x480:d=2 -pix_fmt yuv420p {$out}",
    default => NULL,
  };
}

/**
 * Text body for MIME types that get a stub instead of a rendered file.
 */
function lite_placeholder_stub(string $mime): string {
  $label = LITE_PLACEHOLDER_LABEL;
  return match (TRUE) {
    $mime === 'image/svg+xml' => "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1200\" height=\"900\"><rect width=\"100%\" height=\"100%\" fill=\"#b8b8b8\"/><text x=\"50%\" y=\"50%\" font-size=\"48\" text-anchor=\"middle\" fill=\"#444\">{$label}</text></svg>\n",
    $mime === 'application/pdf' => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n4 0 obj<</Length 60>>stream\nBT /F1 24 Tf 72 700 Td ({$label}) Tj ET\nendstream\nendobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    $mime === 'application/xhtml+xml' => lite_placeholder_hocr(),
    str_starts_with($mime, 'application/xml'), str_starts_with($mime, 'text/xml'), str_ends_with($mime, '+xml') => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<placeholder>{$label}</placeholder>\n",
    str_starts_with($mime, 'application/json'), str_ends_with($mime, '+json') => "{\"placeholder\": \"{$label}\"}\n",
    $mime === 'text/html' => "<!doctype html><title>{$label}</title><p>{$label}</p>\n",
    default => "{$label}\n",
  };
}

/**
 * Minimal valid hOCR page for application/xhtml+xml (Islandora's hOCR derivatives).
 *
 * The hOCR media file is indexed into Solr's OCR highlighting field, whose plugin rejects
 * the whole document ("possible analysis error") when the file is not parseable hOCR.
 */
function lite_placeholder_hocr(): string {
  $words = '';
  $x = 100;
  $right = $x;
  foreach (explode(' ', LITE_PLACEHOLDER_LABEL) as $i => $word) {
    $x1 = $x + 40 * strlen($word);
    $words .= sprintf('<span class="ocrx_word" id="word_1_%d" title="bbox %d 400 %d 500; x_wconf 100">%s</span> ', $i + 1, $x, $x1, htmlspecialchars($word, ENT_XML1));
    $right = $x1;
    $x = $x1 + 20;
  }
  $words = rtrim($words);
  $title = htmlspecialchars(LITE_PLACEHOLDER_LABEL, ENT_XML1);
  return <<<HOCR
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
<title>{$title}</title>
<meta http-equiv="Content-Type" content="text/html;charset=utf-8"/>
<meta name="ocr-system" content="lite-placeholder"/>
<meta name="ocr-capabilities" content="ocr_page ocr_carea ocr_par ocr_line ocrx_word"/>
</head>
<body>
<div class="ocr_page" id="page_1" title="bbox 0 0 1200 900; ppageno 0">
<div class="ocr_carea" id="block_1_1" title="bbox 100 400 {$right} 500">
<p class="ocr_par" id="par_1_1" title="bbox 100 400 {$right} 500">
<span class="ocr_line" id="line_1_1" title="bbox 100 400 {$right} 500">{$words}</span>
</p>
</div>
</div>
</body>
</html>

HOCR;
}

/**
 * File extension for a MIME type.
 */
function lite_placeholder_extension(string $mime): string {
  static $map = [
    'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'image/tiff' => 'tif', 'image/jp2' => 'jp2', 'image/jpx' => 'jp2', 'image/jpm' => 'jp2',
    'image/svg+xml' => 'svg', 'image/bmp' => 'bmp', 'application/pdf' => 'pdf',
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/vnd.wave' => 'wav',
    'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-msvideo' => 'avi', 'video/ogg' => 'ogv',
    'video/x-matroska' => 'mkv', 'video/x-m4v' => 'm4v', 'video/mpeg' => 'mpg',
    'application/xhtml+xml' => 'xhtml', 'application/gzip' => 'gz', 'application/x-gzip' => 'gz', 'application/x-tar' => 'tar',
    'text/plain' => 'txt', 'text/html' => 'html', 'text/csv' => 'csv', 'text/vtt' => 'vtt', 'application/xml' => 'xml',
    'text/xml' => 'xml', 'application/json' => 'json', 'application/zip' => 'zip', 'application/warc' => 'warc',
    'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
  ];
  if (isset($map[$mime])) {
    return $map[$mime];
  }
  $sub = substr($mime, strpos($mime, '/') + 1) ?: 'bin';
  $sub = preg_replace('/^(x-|vnd\.)/', '', $sub);
  return preg_replace('/[^a-z0-9]+/', '', strtolower($sub)) ?: 'bin';
}

/**
 * MIME type guessed from a file extension (for paths referenced outside file_managed).
 */
function lite_placeholder_mime_for_extension(string $ext): string {
  static $map = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'svg' => 'image/svg+xml', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'jp2' => 'image/jp2', 'bmp' => 'image/bmp',
    'ico' => 'image/png', 'pdf' => 'application/pdf', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
    'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'vtt' => 'text/vtt', 'xml' => 'application/xml', 'json' => 'application/json',
    'html' => 'text/html', 'zip' => 'application/zip',
  ];
  return $map[strtolower($ext)] ?? 'application/octet-stream';
}

/**
 * Local directory of a stream wrapper scheme, or NULL when it is not a local wrapper.
 */
function lite_placeholder_scheme_dir(string $scheme): ?string {
  static $dirs = [];
  if (!array_key_exists($scheme, $dirs)) {
    $wrapper = \Drupal::service('stream_wrapper_manager')->getViaScheme($scheme);
    $dir = $wrapper instanceof LocalStream ? $wrapper->getDirectoryPath() : NULL;
    if ($dir !== NULL && !str_starts_with($dir, '/')) {
      $dir = DRUPAL_ROOT . '/' . $dir;
    }
    $dirs[$scheme] = $dir;
  }
  return $dirs[$scheme];
}

/**
 * Writes the generic file for $mime at $path (absolute) if missing.
 */
function lite_placeholder_write(string $mime, string $path): bool {
  if (file_exists($path)) {
    return TRUE;
  }
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
    echo "WARN: cannot create {$dir}\n";
    return FALSE;
  }
  $cmd = lite_placeholder_command($mime, escapeshellarg($path));
  if ($cmd !== NULL) {
    exec($cmd . ' 2>&1', $output, $status);
    if ($status !== 0 || !file_exists($path)) {
      echo "WARN: could not render {$mime} (" . implode(' ', $output) . "); using a stub\n";
    }
  }
  if (!file_exists($path)) {
    file_put_contents($path, lite_placeholder_stub($mime));
  }
  return file_exists($path);
}

/**
 * The generic file for a (scheme, mime) pair: ['uri' => ..., 'path' => ..., 'size' => ...,
 * 'slug' => ..., 'ext' => ...], generated once; NULL when the scheme is not local.
 */
function lite_placeholder_generic(string $scheme, string $mime): ?array {
  static $cache = [];
  $key = "{$scheme}|{$mime}";
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }
  $base = lite_placeholder_scheme_dir($scheme);
  if ($base === NULL) {
    return $cache[$key] = NULL;
  }
  $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($mime));
  $ext = lite_placeholder_extension($mime);
  $path = $base . '/' . LITE_PLACEHOLDER_DIR . "/{$slug}.{$ext}";
  $existed = file_exists($path);
  if (!lite_placeholder_write($mime, $path)) {
    return $cache[$key] = NULL;
  }
  if (!$existed) {
    echo "generic file for {$mime}: {$scheme}://" . LITE_PLACEHOLDER_DIR . "/{$slug}.{$ext}\n";
  }
  return $cache[$key] = [
    'uri' => "{$scheme}://" . LITE_PLACEHOLDER_DIR . "/{$slug}.{$ext}",
    'path' => $path,
    'size' => filesize($path),
    'slug' => $slug,
    'ext' => $ext,
  ];
}

/**
 * chown everything under the local wrappers to nginx.
 */
function lite_placeholder_chown(): void {
  foreach (['public', 'private'] as $scheme) {
    $real = lite_placeholder_scheme_dir($scheme);
    if ($real && is_dir($real)) {
      exec('chown -R nginx:nginx ' . escapeshellarg($real));
    }
  }
}
