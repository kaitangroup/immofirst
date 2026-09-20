<?php

declare(strict_types=1);

use Drupal\node\Entity\Node;

$csv = DRUPAL_ROOT . '/search_requests.csv';

if (!file_exists($csv)) {
  throw new Exception("CSV not found: {$csv}");
}

/**
 * --------------------------------------------------------------------------
 * Taxonomy helper
 * --------------------------------------------------------------------------
 */
function term_id(string $vocabulary, string $name): ?int {

  $name = trim($name);

  if ($name === '') {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => $vocabulary,
      'name' => $name,
    ]);

  if (!$terms) {
    return NULL;
  }

  return (int) reset($terms)->id();
}

/**
 * --------------------------------------------------------------------------
 * Generate title (matches SearchRequestNodeCreator)
 * --------------------------------------------------------------------------
 */
function generate_title(array $row): string {

  $map = [
    'apartment' => ['noun' => 'Wohnung', 'rooms_prefix' => TRUE],
    'house' => ['noun' => 'Einfamilienhaus', 'rooms_prefix' => FALSE],
    'land' => ['noun' => 'Grundstück', 'rooms_prefix' => FALSE],
    'garage' => ['noun' => 'Stellplatz', 'rooms_prefix' => FALSE],
    'commercial' => ['noun' => 'Gewerbeimmobilie', 'rooms_prefix' => FALSE],
  ];

  $property = $row['property_type'] ?? 'apartment';
  $meta = $map[$property] ?? ['noun' => 'Immobilie', 'rooms_prefix' => FALSE];

  $noun = $meta['noun'];

  if ($meta['rooms_prefix']) {
    $rooms = (int) ($row['rooms_min'] ?? 0);

    if ($rooms > 0) {
      $noun = $rooms . '-Zimmer ' . $noun;
    }
  }

  $location = trim($row['location'] ?? '');

  return trim($noun . ($location ? " in {$location}" : '') . ' gesucht');
}

/**
 * --------------------------------------------------------------------------
 * Delete old search_request nodes
 * --------------------------------------------------------------------------
 */

$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'search_request')
  ->execute();

$deleted = count($nids);

if ($deleted) {
  $nodes = Node::loadMultiple($nids);

  \Drupal::entityTypeManager()
    ->getStorage('node')
    ->delete($nodes);
}

print "Deleted {$deleted} old search requests.\n";

/**
 * --------------------------------------------------------------------------
 * Read CSV
 * --------------------------------------------------------------------------
 */

$handle = fopen($csv, 'r');

$header = fgetcsv($handle);

$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

$header = array_map(function ($value) {
  return strtolower(trim($value));
}, $header);

$created = 0;

while (($row = fgetcsv($handle)) !== FALSE) {

  $data = array_combine($header, $row);

  $get = function ($key, $default = '') use ($data) {
    return $data[$key] ?? $default;
  };

  $node = Node::create([

    'type' => 'search_request',

    'title' => generate_title($data),

    'status' => 1,

    /*
     * Step 1
     */
    'field_request_type' => $get('request_type'),
    'field_property_type' => $get('property_type'),
    'field_location' => $get('location'),
    'field_postal_code' => $get('postal_code'),
    'field_radius' => (int) $get('radius_km'),

    /*
     * Step 2
     */
    'field_area_min' => $get('area_min'),
    'field_area_max' => $get('area_max'),

    'field_rooms_min' => $get('rooms_min'),
    'field_rooms_max' => $get('rooms_max'),

    'field_price_min' => $get('price_min'),
    'field_price_max' => $get('price_max'),

    'field_lease_price_min' => $get('lease_price_min'),
    'field_lease_price_max' => $get('lease_price_max'),

    'field_construction_year' => $get('construction_year'),

    'field_land_size_min' => $get('land_size_min'),
    'field_land_size_max' => $get('land_size_max'),

    'field_usage' => $get('usage'),
    'field_parking_type' => $get('parking_type'),
    'field_vehicle_type' => $get('vehicle_type'),
    'field_commercial_type' => $get('commercial_type'),

    /*
     * Step 3
     */
    'field_notes' => $get('notes'),

    /*
     * Step 4
     */
    'field_first_name' => $get('first_name'),
    'field_last_name' => $get('last_name'),
    'field_email' => $get('email'),
    'field_phone' => $get('phone'),
    'field_consent' => (bool) $get('consent'),

    /*
     * System
     */
    'field_status' => $get('status') ?: 'new',
    'field_reference_number' => $get('reference_number'),

    /*
     * field_geo intentionally omitted.
     * Geocoder + Mapbox will populate it automatically.
     */
  ]);

  /**
   * Requester Type taxonomy
   */
  if ($get('requester_type') !== '') {

    $tid = term_id('requester_type', $get('requester_type'));

    if ($tid) {
      $node->set('field_requester_type', [
        'target_id' => $tid,
      ]);
    }
  }

  /**
   * Search Criteria taxonomy
   * CSV format:
   * Seeblick|Balkon|Ruhige Lage
   */
  if ($get('criteria') !== '') {

    $items = explode('|', $get('criteria'));

    $references = [];

    foreach ($items as $item) {

      $tid = term_id('search_criteria', trim($item));

      if ($tid) {
        $references[] = [
          'target_id' => $tid,
        ];
      }
    }

    if ($references) {
      $node->set('field_criteria', $references);
    }
  }

  $node->save();

  $created++;
}

fclose($handle);

print "Created {$created} search requests.\n";
print "Import completed successfully.\n";