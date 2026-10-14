<?php

/**
 * @file
 * Post update functions for ImmoFirst Search Request.
 */

declare(strict_types=1);

use Drupal\Core\Cache\Cache;
use Drupal\immofirst_search_request\Service\SearchRequestNodeCreator;

/**
 * Changes the Such-ID prefix from "SA-" to "IG-" ("Immobiliengesuch").
 *
 * "SA-2026-000283" becomes "IG-2026-000283": only the prefix changes, the
 * year and number are kept, so every ID stays unique and still identifies
 * the same request. Only values that are exactly "SA-<4 digits>-<digits>"
 * are touched; anything else (empty, non-standard values) is left as is.
 *
 * Written directly to the field tables (current and revision rows) in one
 * transaction instead of re-saving the nodes, so no entity hooks run — no
 * e-mail 4.1, no geocoding, no new revision, no changed "updated" date.
 * Caches of the affected nodes are cleared afterwards.
 *
 * Safe to run again: already migrated values no longer match the pattern,
 * and Drupal records a post update as done after it has run once.
 */
function immofirst_search_request_post_update_reference_prefix_ig(): string {
  $old = SearchRequestNodeCreator::LEGACY_REFERENCE_NUMBER_PREFIX;
  $new = SearchRequestNodeCreator::REFERENCE_NUMBER_PREFIX;
  $pattern = '/^' . preg_quote($old, '/') . '(\d{4}-\d+)$/';
  $database = \Drupal::database();
  $tables = ['node__field_reference_number', 'node_revision__field_reference_number'];

  // Refuse to run (and change nothing) if a converted value would collide
  // with an ID that already exists on another request.
  $existing = $database->select('node__field_reference_number', 'r')
    ->fields('r', ['entity_id', 'field_reference_number_value'])
    ->condition('field_reference_number_value', $database->escapeLike($new) . '%', 'LIKE')
    ->execute()->fetchAllKeyed(1, 0);

  $changes = [];
  foreach ($tables as $table) {
    $rows = $database->select($table, 'r')
      ->fields('r', ['entity_id', 'revision_id', 'langcode', 'delta', 'field_reference_number_value'])
      ->condition('bundle', 'search_request')
      ->condition('field_reference_number_value', $database->escapeLike($old) . '%', 'LIKE')
      ->execute()->fetchAll();
    foreach ($rows as $row) {
      if (!preg_match($pattern, $row->field_reference_number_value, $m)) {
        continue;
      }
      $value = $new . $m[1];
      if (isset($existing[$value]) && (int) $existing[$value] !== (int) $row->entity_id) {
        throw new \RuntimeException(sprintf('Such-ID migration stopped, nothing changed: %s (node %d) would collide with node %d.', $value, $row->entity_id, $existing[$value]));
      }
      $changes[] = [$table, $row, $value];
    }
  }

  $transaction = $database->startTransaction();
  try {
    $nids = [];
    foreach ($changes as [$table, $row, $value]) {
      $database->update($table)
        ->fields(['field_reference_number_value' => $value])
        ->condition('entity_id', $row->entity_id)
        ->condition('revision_id', $row->revision_id)
        ->condition('langcode', $row->langcode)
        ->condition('delta', $row->delta)
        // Guard: only the exact value read above.
        ->condition('field_reference_number_value', $row->field_reference_number_value)
        ->execute();
      $nids[(int) $row->entity_id] = (int) $row->entity_id;
    }
  }
  catch (\Throwable $e) {
    $transaction->rollBack();
    throw $e;
  }
  unset($transaction);

  if ($nids) {
    \Drupal::entityTypeManager()->getStorage('node')->resetCache(array_values($nids));
    Cache::invalidateTags(array_merge(['node_list'], array_map(static fn (int $nid): string => "node:$nid", array_values($nids))));
  }

  $current = count(array_filter($changes, static fn ($c) => $c[0] === 'node__field_reference_number'));
  $message = sprintf('Such-ID prefix %s → %s: %d search request(s) changed (%d field row(s) incl. revisions).', $old, $new, count($nids), count($changes));
  \Drupal::logger('immofirst_search_request')->notice($message . ' Current values changed: @c.', ['@c' => $current]);
  return $message;
}
