<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds and saves the "suchauftrag" node from collected wizard data.
 */
final class SearchRequestNodeCreator {

  /**
   * Human-readable labels for property types, used in the generated title.
   *
   * @var array<string, array{noun: string, gendered_article: string}>
   */
  private const PROPERTY_TYPE_TITLES = [
    'apartment' => ['noun' => 'Wohnung', 'rooms_prefix' => TRUE],
    'house' => ['noun' => 'Einfamilienhaus', 'rooms_prefix' => FALSE],
    'land' => ['noun' => 'Grundstück', 'rooms_prefix' => FALSE],
    'garage' => ['noun' => 'Stellplatz', 'rooms_prefix' => FALSE],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates and saves one published "suchauftrag" node from wizard data.
   *
   * @param array<string, mixed> $data
   *   Full wizard data, keyed step1..step5 (see SearchRequestSession).
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  public function createFromWizardData(array $data): NodeInterface {
    $step1 = $data['step1'] ?? [];
    $step2 = $data['step2'] ?? [];
    $step3 = $data['step3'] ?? [];
    $step4 = $data['step4'] ?? [];
    $step5 = $data['step5'] ?? [];

    $requestType = (string) ($step1['request_type'] ?? 'mieten');
    $propertyType = (string) ($step2['property_type'] ?? 'apartment');
    $location = (string) ($step3['location'] ?? '');

    $storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create([
      'type' => 'suchauftrag',
      'title' => $this->generateTitle($propertyType, $step3, $location),
      'status' => 1,
      'field_request_type' => $requestType,
      'field_request_date' => date('Y-m-d'),
      'field_property_icon' => $propertyType,
      'field_location' => $location,
      'field_rooms' => $this->minValue($step3['rooms'] ?? NULL),
      'field_area' => $this->minValue($step3['area'] ?? NULL),
      'field_price' => $this->minValue($step3['price'] ?? NULL),
      'field_description' => [
        'value' => (string) ($step3['description'] ?? ''),
        'format' => 'basic_html',
      ],
      'field_search_criteria' => $this->buildCriteriaJson($step2, $step3, $step4, $step5),
    ]);

    $node->save();

    return $node;
  }

  /**
   * Builds the "N-Zimmer Wohnung in Ort gesucht" style title.
   *
   * @param array<string, mixed> $step3
   */
  private function generateTitle(string $propertyType, array $step3, string $location): string {
    $meta = self::PROPERTY_TYPE_TITLES[$propertyType] ?? ['noun' => 'Immobilie', 'rooms_prefix' => FALSE];
    $noun = $meta['noun'];

    if (!empty($meta['rooms_prefix'])) {
      $roomsMin = $step3['rooms']['min'] ?? NULL;
      if (is_numeric($roomsMin) && (int) $roomsMin > 0) {
        $noun = ((int) $roomsMin) . '-Zimmer ' . $noun;
      }
    }

    $locationPart = $location !== '' ? ' in ' . $location : '';

    return trim($noun . $locationPart . ' gesucht');
  }

  /**
   * Extracts the "min" value from a {min, max} pair, as a string.
   *
   * Node fields only ever receive the minimum (per spec); maximums are
   * preserved separately in field_search_criteria's JSON blob.
   */
  private function minValue(mixed $pair): string {
    if (is_array($pair) && isset($pair['min']) && $pair['min'] !== '' && $pair['min'] !== NULL) {
      return (string) $pair['min'];
    }

    return '';
  }

  /**
   * Builds the field_search_criteria JSON payload.
   *
   * @param array<string, mixed> $step2
   * @param array<string, mixed> $step3
   * @param array<string, mixed> $step4
   * @param array<string, mixed> $step5
   */
  private function buildCriteriaJson(array $step2, array $step3, array $step4, array $step5): string {
    $payload = [
      'property_type' => $step2['property_type'] ?? NULL,
      'rooms' => [
        'min' => $step3['rooms']['min'] ?? NULL,
        'max' => $step3['rooms']['max'] ?? NULL,
      ],
      'area' => [
        'min' => $step3['area']['min'] ?? NULL,
        'max' => $step3['area']['max'] ?? NULL,
      ],
      'price' => [
        'min' => $step3['price']['min'] ?? NULL,
        'max' => $step3['price']['max'] ?? NULL,
      ],
      'criteria' => array_values($step4['criteria'] ?? []),
      'notes' => $step4['notes'] ?? '',
      'contact' => [
        'firstname' => $step5['firstname'] ?? '',
        'lastname' => $step5['lastname'] ?? '',
        'email' => $step5['email'] ?? '',
        'phone' => $step5['phone'] ?? '',
      ],
    ];

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }

}
