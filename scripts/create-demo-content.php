<?php

/**
 * @file
 * Demo content importer for the "Suchauftrag" content type.
 *
 * Creates the 6 sample search requests shown in the reference design
 * (München, Hamburg, Frankfurt, Stuttgart, Köln, Düsseldorf).
 *
 * USAGE:
 *   1. Make sure the content type + fields from config/install/*.yml
 *      have been imported first (see README.md in this package).
 *   2. Run from your Drupal root:
 *        drush scr scripts/create-demo-content.php
 *
 * Safe to re-run: it checks for an existing node with the same title
 * before creating a duplicate.
 */

use Drupal\node\Entity\Node;

$items = [
  [
    'title' => '3-Zimmer-Wohnung gesucht',
    'field_request_type' => 'mieten',
    'field_property_icon' => 'apartment',
    'field_location' => 'München, 10 km Umkreis',
    'field_area' => 'ab 70 m²',
    'field_rooms' => '3',
    'field_price' => 'bis 1.600 €',
    'field_description' => 'Berufstätiges Paar sucht eine moderne Wohnung mit Balkon oder Terrasse.',
    'date_offset' => '0 days', // Heute
  ],
  [
    'title' => 'Wohnung gesucht',
    'field_request_type' => 'kaufen',
    'field_property_icon' => 'apartment',
    'field_location' => 'Hamburg, 15 km Umkreis',
    'field_area' => 'ab 80 m²',
    'field_rooms' => 'ab 3',
    'field_price' => 'bis 600.000 €',
    'field_description' => 'Suche eine Eigentumswohnung mit Stellplatz in guter Lage.',
    'date_offset' => '-1 day', // Gestern
  ],
  [
    'title' => '2-Zimmer-Wohnung gesucht',
    'field_request_type' => 'mieten',
    'field_property_icon' => 'apartment',
    'field_location' => 'Frankfurt am Main, 7 km Umkreis',
    'field_area' => 'ab 50 m²',
    'field_rooms' => '2',
    'field_price' => 'bis 1.200 €',
    'field_description' => 'Studentin sucht eine helle Wohnung mit guter Anbindung an den ÖPNV.',
    'date_offset' => '-11 days', // -> 11.05. style historical date
  ],
  [
    'title' => 'Einfamilienhaus gesucht',
    'field_request_type' => 'kaufen',
    'field_property_icon' => 'home',
    'field_location' => 'Stuttgart, 20 km Umkreis',
    'field_area' => 'ab 120 m²',
    'field_rooms' => 'ab 4',
    'field_price' => 'bis 800.000 €',
    'field_description' => 'Familie mit 2 Kindern sucht ein freistehendes Einfamilienhaus mit Garten.',
    'date_offset' => '-12 days',
  ],
  [
    'title' => 'Grundstück gesucht',
    'field_request_type' => 'kaufen',
    'field_property_icon' => 'plot',
    'field_location' => 'Köln, 20 km Umkreis',
    'field_area' => 'ab 400 m²',
    'field_rooms' => '',
    'field_price' => 'bis 450.000 €',
    'field_description' => 'Baugrundstück für ein Einfamilienhaus in naturnaher Lage gesucht.',
    'date_offset' => '-13 days',
  ],
  [
    'title' => 'Garage oder Stellplatz gesucht',
    'field_request_type' => 'mieten',
    'field_property_icon' => 'garage',
    'field_location' => 'Düsseldorf, 5 km Umkreis',
    'field_area' => '',
    'field_rooms' => '',
    'field_price' => 'bis 100 €',
    'field_description' => 'Suche einen Stellplatz oder eine Garage zur langfristigen Miete.',
    'date_offset' => '-14 days',
  ],
];

$created = 0;
$skipped = 0;

foreach ($items as $item) {
  $existing = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
    'type' => 'suchauftrag',
    'title' => $item['title'],
  ]);

  if (!empty($existing)) {
    $skipped++;
    echo " - Übersprungen (existiert bereits): {$item['title']}\n";
    continue;
  }

  $date = new \DateTime($item['date_offset']);

  $node = Node::create([
    'type' => 'suchauftrag',
    'title' => $item['title'],
    'status' => 1,
    'langcode' => 'de',
    'field_request_type' => $item['field_request_type'],
    'field_property_icon' => $item['field_property_icon'],
    'field_location' => $item['field_location'],
    'field_area' => $item['field_area'],
    'field_rooms' => $item['field_rooms'],
    'field_price' => $item['field_price'],
    'field_description' => [
      'value' => $item['field_description'],
      'format' => 'basic_html',
    ],
    'field_request_date' => $date->format('Y-m-d'),
  ]);
  $node->save();

  $created++;
  echo " + Erstellt: {$item['title']} (nid={$node->id()})\n";
}

echo "\nFertig. {$created} Suchaufträge erstellt, {$skipped} übersprungen.\n";
