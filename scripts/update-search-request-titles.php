<?php

declare(strict_types=1);

/**
 * Regenerates the title of every "search_request" node.
 *
 * Titles have NO location and always end with "gesucht". List-field
 * labels (Stellplatz-Art, Art der Gewerbeimmobilie) come from the
 * field's own allowed_values — the same source the node page and the
 * fixed SearchRequestNodeCreator use — so "grundstuecke_flaechen"
 * becomes "Grundstücke & Flächen gesucht".
 *
 * Usage:
 *   DRY_RUN=1 drush scr update-search-request-titles.php   (preview only)
 *   drush scr update-search-request-titles.php             (saves changes)
 *
 * Keep generate_clean_title() in sync with
 * SearchRequestNodeCreator::generateTitle().
 */

use Drupal\node\Entity\Node;

if (!function_exists('sr_list_label')) {

  /**
   * Label for a list field's stored key, from allowed_values.
   *
   * Warns (once per key) when the option is missing, so a fallback
   * humanized title is visible instead of silently wrong.
   */
  function sr_list_label(Node $node, string $field): string {
    static $warned = [];

    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $key = (string) $node->get($field)->value;
    $allowed = $node->getFieldDefinition($field)
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values') ?? [];

    $label = (string) ($allowed[$key] ?? '');
    if ($label !== '') {
      return $label;
    }

    if (!isset($warned["$field:$key"])) {
      $warned["$field:$key"] = TRUE;
      print "WARNING: no allowed_values label for {$field} = '{$key}' — using a humanized fallback. Add this option to the field.\n";
    }
    return ucfirst(str_replace('_', ' ', $key));
  }

  function sr_format_number(mixed $value): ?string {
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }
    $float = (float) $value;
    return $float == (int) $float ? (string) (int) $float : rtrim(rtrim((string) $float, '0'), '.');
  }

  function generate_clean_title(Node $node): string {
    $get = static function (string $field) use ($node) {
      return $node->hasField($field) && !$node->get($field)->isEmpty()
        ? $node->get($field)->value
        : NULL;
    };

    $propertyType = $get('field_property_type') ?? 'apartment';

    switch ($propertyType) {
      case 'apartment':
      case 'house':
        $noun = ($propertyType === 'apartment') ? 'Wohnung' : 'Haus';
        $roomsMin = $get('field_rooms_min');
        $roomsMax = $get('field_rooms_max');
        $min_f = sr_format_number($roomsMin);
        $max_f = sr_format_number($roomsMax);
        if ($min_f !== NULL && $max_f !== NULL && $min_f !== $max_f) {
          if ((float) $roomsMin > (float) $roomsMax) {
            [$min_f, $max_f] = [$max_f, $min_f];
          }
          return $min_f . '–' . $max_f . ' Zimmer ' . $noun . ' gesucht';
        }
        $val = $min_f ?? $max_f;
        return $val !== NULL
          ? $val . ' Zimmer ' . $noun . ' gesucht'
          : $noun . ' gesucht';

      case 'land':
        $landMin = $get('field_land_size_min');
        $landMax = $get('field_land_size_max');
        $min_f = sr_format_number($landMin);
        $max_f = sr_format_number($landMax);
        if ($min_f !== NULL && $max_f !== NULL && $min_f !== $max_f) {
          if ((float) $landMin > (float) $landMax) {
            [$min_f, $max_f] = [$max_f, $min_f];
          }
          return $min_f . '–' . $max_f . ' m² Grundstück gesucht';
        }
        if ($min_f !== NULL) {
          return 'ab ' . $min_f . ' m² Grundstück gesucht';
        }
        if ($max_f !== NULL) {
          return 'bis ' . $max_f . ' m² Grundstück gesucht';
        }
        return 'Grundstück gesucht';

      case 'garage':
        $label = sr_list_label($node, 'field_parking_type');
        return $label !== '' ? $label . ' gesucht' : 'Stellplatz gesucht';

      case 'commercial':
        $label = sr_list_label($node, 'field_commercial_type');
        return $label !== '' ? $label . ' gesucht' : 'Gewerbeimmobilie gesucht';

      default:
        return 'Immobilie gesucht';
    }
  }

}

$dry_run = getenv('DRY_RUN') === '1';

$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'search_request')
  ->sort('nid')
  ->execute();

$storage = \Drupal::entityTypeManager()->getStorage('node');
$count = 0;

foreach (array_chunk($nids, 50) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $node) {
    /** @var \Drupal\node\NodeInterface $node */
    $new_title = generate_clean_title($node);

    if ($node->label() === $new_title) {
      continue;
    }

    print "Node {$node->id()}: \"{$node->label()}\" → \"{$new_title}\"\n";

    if (!$dry_run) {
      $node->setTitle($new_title);
      // Title fix only — don't create a new revision per node.
      $node->setNewRevision(FALSE);
      $node->save();
    }
    $count++;
  }
  $storage->resetCache($chunk);
}

print $dry_run
  ? "\nDRY RUN: {$count} titles would be updated. Nothing was saved.\n"
  : "\nCompleted: {$count} titles updated.\n";