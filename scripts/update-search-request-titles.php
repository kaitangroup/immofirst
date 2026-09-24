<?php

declare(strict_types=1);

use Drupal\node\Entity\Node;

function get_list_label(Node $node, string $field): string {
  if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
    return '';
  }
  $value = $node->get($field)->value;
  $allowed = $node->getFieldDefinition($field)?->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
  return $allowed[$value] ?? ucfirst(str_replace('_', ' ', $value));
}

// Generate title WITHOUT location.
function generate_clean_title(Node $node): string {
  $get = function (string $field) use ($node) {
    return $node->hasField($field) && !$node->get($field)->isEmpty()
      ? $node->get($field)->value
      : NULL;
  };

  $propertyType = $get('field_property_type') ?? 'apartment';
  $roomsMin = $get('field_rooms_min');
  $roomsMax = $get('field_rooms_max');
  $landMin = $get('field_land_size_min');
  $landMax = $get('field_land_size_max');

  $format_number = static function (mixed $value): ?string {
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }
    $float = (float) $value;
    return $float == (int) $float ? (string) (int) $float : rtrim(rtrim((string) $float, '0'), '.');
  };

  switch ($propertyType) {
    case 'apartment':
    case 'house':
      $noun = ($propertyType === 'apartment') ? 'Wohnung' : 'Haus';
      $min_f = $format_number($roomsMin);
      $max_f = $format_number($roomsMax);
      if ($min_f !== NULL && $max_f !== NULL && $min_f !== $max_f) {
        if ((float) $roomsMin > (float) $roomsMax) {
          [$min_f, $max_f] = [$max_f, $min_f];
        }
        return $min_f . '–' . $max_f . ' Zimmer ' . $noun . ' gesucht';
      }
      $val = $min_f !== NULL ? $min_f : $max_f;
      if ($val !== NULL) {
        return $val . ' Zimmer ' . $noun . ' gesucht';
      }
      return $noun . ' gesucht';

    case 'land':
      $min_f = $format_number($landMin);
      $max_f = $format_number($landMax);
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
      $parkingType = get_list_label($node, 'field_parking_type') ?: $get('field_parking_type');
      if ($parkingType !== NULL && trim((string)$parkingType) !== '') {
        return trim((string)$parkingType) . ' gesucht';
      }
      return 'Stellplatz gesucht';

    case 'commercial':
      $commercialType = get_list_label($node, 'field_commercial_type') ?: $get('field_commercial_type');
      if ($commercialType !== NULL && trim((string)$commercialType) !== '') {
        return trim((string)$commercialType) . ' gesucht';
      }
      return 'Gewerbeimmobilie gesucht';

    default:
      return 'Immobilie gesucht';
  }
}

$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'search_request')
  ->execute();

$nodes = Node::loadMultiple($nids);

$count = 0;

foreach ($nodes as $node) {
  $new_title = generate_clean_title($node);

  if ($node->label() !== $new_title) {
    $node->setTitle($new_title);
    $node->save();
    $count++;
    print "Updated Node {$node->id()} → {$new_title}\n";
  }
}

print "\nCompleted: {$count} titles updated.\n";
