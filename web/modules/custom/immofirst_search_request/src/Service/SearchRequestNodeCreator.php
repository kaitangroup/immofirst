<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Builds and saves the "search_request" node from collected wizard data.
 *
 * PHASE 2 NOTE: this class used to create "suchauftrag" nodes with most
 * wizard answers flattened into one field_search_criteria JSON blob
 * (see immofirst_search_request.install's Phase 1 architecture note).
 * It now creates "search_request" nodes instead, with one real Drupal
 * field per wizard answer (field definitions: see
 * _immofirst_search_request_field_definitions() in the .install file).
 * No "suchauftrag" node is read or written anywhere in this class
 * anymore.
 */
final class SearchRequestNodeCreator {

  /**
   * Human-readable labels for property types, used in the generated title.
   *
   * @var array<string, array{noun: string, rooms_prefix: bool}>
   */
  private const PROPERTY_TYPE_TITLES = [
    'apartment' => ['noun' => 'Wohnung', 'rooms_prefix' => TRUE],
    'house' => ['noun' => 'Einfamilienhaus', 'rooms_prefix' => FALSE],
    'land' => ['noun' => 'Grundstück', 'rooms_prefix' => FALSE],
    'garage' => ['noun' => 'Stellplatz', 'rooms_prefix' => FALSE],
    'commercial' => ['noun' => 'Gewerbeimmobilie', 'rooms_prefix' => FALSE],
  ];

  /**
   * "SA-<year>-<6-digit id>" reference number format.
   *
   * Kept identical to the one the wizard's own Step 5 success screen
   * (SearchRequestWizardForm::buildStep5()) and ThankYouController
   * already compute independently from the node id — this constant
   * exists only so field_reference_number stores the SAME string, not
   * to change what either of those (untouched) UI code paths shows.
   */
  private const REFERENCE_NUMBER_FORMAT = 'SA-%s-%06d';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates and saves one published "search_request" node from wizard data.
   *
   * @param array<string, mixed> $data
   *   Full wizard data, keyed step1..step5 (see SearchRequestSession).
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  public function createFromWizardData(array $data): NodeInterface {
    $values = $this->mapWizardDataToSearchRequest($data);

    $storage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create($values);
    $node->save();

    // field_reference_number depends on the node id, which only exists
    // once the node has been saved once, so it's filled in with a
    // second, cheap save. This does not change what the wizard displays:
    // Step 5's success screen and ThankYouController both already
    // compute this exact same "SA-<year>-<id>" string themselves from
    // $node->id(), independently of this field.
    $referenceNumber = $this->generateReferenceNumber($node);
    $node->set('field_reference_number', $referenceNumber);
    $node->save();

    return $node;
  }

  /**
   * Maps full wizard session data onto search_request field values.
   *
   * Responsibilities: reads all session step buckets, normalizes raw
   * wizard values (min/max pairs, blank strings, checkbox lists) into
   * the scalar/array shapes the Field API expects, and maps every
   * wizard answer onto its search_request field machine name (see
   * _immofirst_search_request_field_definitions() in
   * immofirst_search_request.install for the authoritative field
   * list). Does not touch the entity type manager or save anything —
   * pure data-in, array-out.
   *
   * @param array<string, mixed> $sessionData
   *   Full wizard data, keyed step1..step5 (see SearchRequestSession):
   *   - step1: request_type
   *   - step2: property_type
   *   - step3: location, radius, plus whichever of
   *     area/rooms/price/land_size (each {min,max}) and
   *     baujahr/usage/parking_type/vehicle_type/commercial_type apply
   *     for the selected request_type + property_type
   *     (see SearchRequestWizardForm::step2FieldsForSelection()).
   *   - step4: criteria (string[] of machine keys), notes
   *   - step5: firstname, lastname, email, phone, consent
   *
   * @return array<string, mixed>
   *   A node value array ready for $storage->create(), keyed by
   *   search_request's field machine names.
   */
  public function mapWizardDataToSearchRequest(array $sessionData): array {
    $step1 = $sessionData['step1'] ?? [];
    $step2 = $sessionData['step2'] ?? [];
    $step3 = $sessionData['step3'] ?? [];
    $step4 = $sessionData['step4'] ?? [];
    $step5 = $sessionData['step5'] ?? [];

    $requestType = (string) ($step1['request_type'] ?? 'mieten');
    $propertyType = (string) ($step2['property_type'] ?? 'apartment');
    $location = (string) ($step3['location'] ?? '');

    return [
      'type' => 'search_request',
      'title' => $this->generateTitle($propertyType, $step3, $location),
      'status' => 1,

      // ---- Step 1: Gesuchsart / Objektart / Lage ------------------
      'field_request_type' => $requestType,
      'field_property_type' => $propertyType,
      'field_location' => $location,
      'field_radius' => $this->intOrNull($step3['radius'] ?? NULL),

      // ---- Step 2: Anforderungen (which of these are populated ----
      // ---- depends on request_type + property_type, exactly as ----
      // ---- step2FieldsForSelection() decides in the wizard) -------
      'field_area_min' => $this->intOrNull($step3['area']['min'] ?? NULL),
      'field_area_max' => $this->intOrNull($step3['area']['max'] ?? NULL),
      'field_rooms_min' => $this->numOrNull($step3['rooms']['min'] ?? NULL),
      'field_rooms_max' => $this->numOrNull($step3['rooms']['max'] ?? NULL),
      'field_price_min' => $this->numOrNull($step3['price']['min'] ?? NULL),
      'field_price_max' => $this->numOrNull($step3['price']['max'] ?? NULL),
      'field_construction_year' => (string) ($step3['baujahr'] ?? ''),
      'field_land_size_min' => $this->intOrNull($step3['land_size']['min'] ?? NULL),
      'field_land_size_max' => $this->intOrNull($step3['land_size']['max'] ?? NULL),
      'field_usage' => (string) ($step3['usage'] ?? ''),
      'field_parking_type' => (string) ($step3['parking_type'] ?? ''),
      'field_vehicle_type' => (string) ($step3['vehicle_type'] ?? ''),
      'field_commercial_type' => (string) ($step3['commercial_type'] ?? ''),

      // ---- Step 3: Zusätzliche Kriterien / Hinweise ----------------
      // field_criteria is an entity_reference field (unlimited) to
      // search_criteria taxonomy terms, not plain text — $step4
      // ['criteria'] already holds term ids (int), not label strings,
      // per SearchRequestWizardForm::extractStep4Values(). A plain
      // array of scalar ids is exactly what the Field API expects
      // here; each becomes that item's target_id.
      'field_criteria' => array_values($step4['criteria'] ?? []),
      'field_notes' => (string) ($step4['notes'] ?? ''),

      // ---- Step 4: Kontaktdaten -------------------------------------
      'field_first_name' => (string) ($step5['firstname'] ?? ''),
      'field_last_name' => (string) ($step5['lastname'] ?? ''),
      'field_email' => (string) ($step5['email'] ?? ''),
      'field_phone' => (string) ($step5['phone'] ?? ''),
      'field_consent' => (bool) ($step5['consent'] ?? FALSE),

      // field_preferred_contact intentionally omitted: not yet
      // collected anywhere in the wizard (see the field's own
      // description in immofirst_search_request.install — it's
      // reserved for a future wizard addition).

      // ---- System ----------------------------------------------------
      // field_reference_number is filled in after the first save (see
      // createFromWizardData()), once the node id is known.
      'field_status' => 'new',
    ];
  }

  /**
   * Builds the "N-Zimmer Wohnung in Ort gesucht" style title.
   *
   * Unchanged from Phase 1 behavior (only the property-type table
   * gained a 'commercial' entry, which the old suchauftrag path never
   * needed since field_property_icon had no such value there).
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
   * Computes the "SA-<year>-<6-digit id>" reference number for a node.
   */
  private function generateReferenceNumber(NodeInterface $node): string {
    return sprintf(self::REFERENCE_NUMBER_FORMAT, date('Y'), (int) $node->id());
  }

  /**
   * Normalizes a raw wizard numeric value to an int, or NULL if blank.
   *
   * Used for whole-number fields (area, land size, radius). NULL (not
   * '' or 0) is returned for missing/blank input so the Field API
   * leaves the field genuinely empty instead of storing a false zero.
   */
  private function intOrNull(mixed $value): ?int {
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }

    return (int) $value;
  }

  /**
   * Normalizes a raw wizard numeric value to a numeric string, or NULL.
   *
   * Used for decimal fields (rooms, price), which accept string input
   * and handle their own precision/scale. Same blank/NULL handling as
   * intOrNull().
   */
  private function numOrNull(mixed $value): ?string {
    if ($value === NULL || $value === '' || !is_numeric($value)) {
      return NULL;
    }

    return (string) $value;
  }

}