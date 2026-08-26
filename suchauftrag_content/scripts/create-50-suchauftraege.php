<?php

use Drupal\node\Entity\Node;

$cities = [
  'Berlin','Hamburg','München','Köln','Frankfurt',
  'Stuttgart','Düsseldorf','Leipzig','Bremen','Dresden'
];

$types = ['kaufen', 'mieten'];
$icons = ['house', 'apartment', 'villa'];

for ($i = 1; $i <= 50; $i++) {

  $rooms = rand(1,6);
  $area  = rand(45,220);

  if (rand(0,1)) {
    $type = 'kaufen';
    $price = rand(180000, 950000);
    $title = "{$rooms}-Zimmer {$cities[array_rand($cities)]} kaufen";
  }
  else {
    $type = 'mieten';
    $price = rand(650, 3200);
    $title = "{$rooms}-Zimmer Wohnung in {$cities[array_rand($cities)]}";
  }

  $node = Node::create([
    'type'  => 'suchauftrag',
    'status'=> 1,
    'title' => $title,

    'field_request_type' => $type,
    'field_request_date' => date('Y-m-d', strtotime('-'.rand(0,20).' days')),
    'field_property_icon'=> $icons[array_rand($icons)],
    'field_location'     => $cities[array_rand($cities)],
    'field_area'         => $area,
    'field_rooms'        => $rooms,
    'field_price'        => $price,
    'field_description'  =>
      'Wir suchen eine gepflegte Immobilie in guter Lage mit Balkon oder Terrasse.'
  ]);

  $node->save();
}

print "50 Suchaufträge created.\n";