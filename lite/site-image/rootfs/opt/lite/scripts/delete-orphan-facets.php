<?php

/**
 * @file
 * Deletes facets whose field exists in no Search API index.
 *
 * Production databases carry facets for index fields that were removed later; facets
 * validation then aborts the views post-updates ("No available query types were found")
 * during `drush updb`. Run before updb, inside the drupal container:
 *   drush php:script /opt/lite/scripts/delete-orphan-facets.php
 */

$etm = \Drupal::entityTypeManager();
if (!$etm->hasDefinition('facets_facet')) {
  echo "facets not installed, nothing to do\n";
  return;
}
$indexes = $etm->getStorage('search_api_index')->loadMultiple();
$deleted = 0;
foreach ($etm->getStorage('facets_facet')->loadMultiple() as $facet) {
  $field = $facet->getFieldIdentifier();
  foreach ($indexes as $index) {
    if ($index->getField($field)) {
      continue 2;
    }
  }
  echo "deleting orphaned facet {$facet->id()} (field {$field})\n";
  $facet->delete();
  $deleted++;
}
echo "orphaned facets deleted: {$deleted}\n";
