<?php

declare(strict_types=1);

use Drupal\node\Entity\Node;

// Generate title WITHOUT location.
function generate_clean_title(Node $node): string {

  $map = [
    'apartment' => ['noun' => 'Wohnung', 'rooms_prefix' => TRUE],
    'house' => ['noun' => 'Einfamilienhaus', 'rooms_prefix' => FALSE],
    'land' => ['noun' => 'Grundstück', 'rooms_prefix' => FALSE],
    'garage' => ['noun' => 'Stellplatz', 'rooms_prefix' => FALSE],
    'commercial' => ['noun' => 'Gewerbeimmobilie', 'rooms_prefix' => FALSE],
  ];

  $property = $node->get('field_property_type')->value ?? 'apartment';
  $meta = $map[$property] ?? ['noun' => 'Immobilie', 'rooms_prefix' => FALSE];

  $title = $meta['noun'];

  if ($meta['rooms_prefix']) {
    $rooms = (int) ($node->get('field_rooms_min')->value ?? 0);
    if ($rooms > 0) {
      $title = $rooms . '-Zimmer ' . $title;
    }
  }

  return $title . ' gesucht';
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