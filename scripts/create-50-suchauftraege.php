<?php

use Drupal\node\Entity\Node;

$cities = [
  ['80331', 'München'],
  ['10115', 'Berlin'],
  ['50667', 'Köln'],
  ['20095', 'Hamburg'],
  ['70173', 'Stuttgart'],
  ['60311', 'Frankfurt'],
  ['40213', 'Düsseldorf'],
  ['04109', 'Leipzig'],
  ['28195', 'Bremen'],
  ['90402', 'Nürnberg'],
];

$firstNames = [
  'Max','Anna','Lukas','Sofia','Leon','Emma','Paul',
  'Mia','Jonas','Laura','Felix','Julia'
];

$lastNames = [
  'Müller','Schmidt','Weber','Fischer','Becker',
  'Hoffmann','Koch','Richter','Klein','Wolf'
];

$propertyTypes = [
  'wohnung',
  'haus',
  'grundstueck',
  'garage',
  'gewerbe',
];

$transactions = ['kaufen', 'mieten'];

for ($i = 1; $i <= 50; $i++) {

  $city = $cities[array_rand($cities)];
  $first = $firstNames[array_rand($firstNames)];
  $last = $lastNames[array_rand($lastNames)];
  $transaction = $transactions[array_rand($transactions)];
  $property = $propertyTypes[array_rand($propertyTypes)];

  $title = ucfirst($transaction) . ' – ' . ucfirst($property) . ' in ' . $city[1] . " #$i";

  $priceMin = rand(500, 2500);
  $priceMax = $priceMin + rand(300, 2500);

  if ($transaction === 'kaufen') {
    $priceMin *= 150;
    $priceMax *= 180;
  }

  $node = Node::create([
    'type' => 'search_request',
    'title' => $title,
    'status' => 1,

    // Step 1
    'field_transaction_type' => $transaction,
    'field_property_type' => $property,
    'field_postal_code' => $city[0],
    'field_city' => $city[1],
    'field_radius' => [10,20,30,50][array_rand([10,20,30,50])],

    // Step 2
    'field_price_min' => $priceMin,
    'field_price_max' => $priceMax,
    'field_area_min' => rand(35,120),
    'field_area_max' => rand(121,260),
    'field_rooms_min' => rand(1,3),
    'field_rooms_max' => rand(3,7),

    // Step 4
    'field_firstname' => $first,
    'field_lastname' => $last,
    'field_email' => strtolower($first . '.' . $last . $i . '@example.de'),
    'field_whatsapp' => '171' . rand(1000000,9999999),

    // Workflow
    'field_request_status' => 'active',
  ]);

  $node->save();

  print "Created: {$node->id()} - {$title}\n";
}

print "\nDone! 50 search requests created.\n";