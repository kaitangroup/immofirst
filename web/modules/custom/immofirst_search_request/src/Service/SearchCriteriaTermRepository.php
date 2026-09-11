<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Loads Step 3's "Zusätzliche Kriterien" checkbox options from the
 * "search_criteria" taxonomy vocabulary.
 *
 * Replaces the previous hardcoded CRITERIA_GROUPS PHP array in
 * SearchRequestWizardForm. The underlying data is unchanged — every
 * term is still a verbatim transcription of Tab.2.1 of
 * ImmoFirst_260909.xlsx, stored as taxonomy_term entities instead
 * of PHP literals (see
 * _immofirst_search_request_criteria_term_definitions() in
 * immofirst_search_request.install for the canonical source data and
 * per-option ordering).
 */
final class SearchCriteriaTermRepository {

  private const VOCABULARY_ID = 'search_criteria';

  /**
   * Canonical Step 3 GROUP order per Objektart, per Tab.2.1
   * (ImmoFirst_260909.xlsx).
   *
   * Taxonomy term 'weight' controls OPTION order within a group (see
   * the query below) and stays exactly as-is. It cannot, on its own,
   * also encode correct GROUP order once a group is shared across
   * property types: "Bonität & zusätzliche Angaben", for instance, is
   * one shared set of terms whose weight reflects wherever it was
   * first introduced in the flat definition list
   * (immofirst_search_request.install) — appropriate for Wohnung,
   * where it happens to come last anyway, but not for Haus,
   * Grundstück, or Garage, where that same low-relative weight would
   * otherwise pull it to the front. This explicit, per-Objektart list
   * is the deterministic source of truth for group order instead.
   *
   * @var array<string, string[]>
   */
  private const GROUP_ORDER = [
    'apartment' => [
      'Ausstattung',
      'Gebäude & Zustand',
      'Parkmöglichkeiten',
      'Lage im Gebäude',
      'Außenbereiche',
      'Sonstige Kriterien',
      'Bonität & zusätzliche Angaben',
    ],
    'house' => [
      'Ausstattung',
      'Haustyp',
      'Grundstück & Außenbereich',
      'Parkmöglichkeiten',
      'Zustand',
      'Sonstige Kriterien',
      'Nutzung',
      'Bonität & zusätzliche Angaben',
    ],
    'land' => [
      'Grundstücksart',
      'Bebauung',
      'Bebauungsmöglichkeiten',
      'Lage / Besonderheiten',
      'Bonität & zusätzliche Angaben',
    ],
    'garage' => [
      'Ausführungsart',
      'Nutzung',
      'Größe / Nutzung',
      'Bonität & zusätzliche Angaben',
    ],
    'commercial' => [
      'Bonität & zusätzliche Angaben',
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns Step 3's checkbox groups for one Objektart, in Excel order.
   *
   * @param string $propertyType
   *   One of apartment/house/land/garage/commercial (the same machine
   *   values Step 2's cards already submit).
   *
   * @return array<string, array<int, string>>
   *   [group label => [term id => term label]]. Group order follows
   *   GROUP_ORDER above; within each group, option order follows
   *   taxonomy term weight (then tid), unchanged from before — i.e.
   *   the same shape and per-option ordering
   *   SearchRequestWizardForm::buildStep3() previously read from the
   *   CRITERIA_GROUPS constant, just keyed by term id instead of a
   *   machine-key'd label string.
   */
  public function groupsForPropertyType(string $propertyType): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // accessCheck(FALSE): these terms are structural/internal wizard
    // configuration (which checkboxes exist), not per-user content —
    // equivalent to reading a config array, not rendering an entity.
    $ids = $storage->getQuery()
      ->condition('vid', self::VOCABULARY_ID)
      ->condition('field_property_types', $propertyType)
      ->sort('weight')
      ->sort('tid')
      ->accessCheck(FALSE)
      ->execute();

    if (!$ids) {
      return [];
    }

    /** @var array<int, \Drupal\taxonomy\TermInterface> $terms */
    $terms = $storage->loadMultiple($ids);

    $groups = [];
    // Iterate $ids (not $terms) to guarantee the sort order from the
    // query above survives loadMultiple(), which does not itself
    // promise to preserve the order its $ids argument was given in.
    // This is still what determines OPTION order within each group.
    foreach ($ids as $id) {
      $term = $terms[$id] ?? NULL;
      if (!$term instanceof TermInterface) {
        continue;
      }

      $groupLabel = (string) ($term->get('field_group')->value ?? '');
      $groups[$groupLabel][(int) $id] = (string) $term->label();
    }

    return $this->orderGroups($groups, $propertyType);
  }

  /**
   * Re-orders an assembled [group label => options] array to match
   * GROUP_ORDER for the given Objektart, without touching any group's
   * internal option order.
   *
   * @param array<string, array<int, string>> $groups
   *   As built in groupsForPropertyType(), still in incidental
   *   weight-of-first-term order at the top level.
   * @param string $propertyType
   *   One of apartment/house/land/garage/commercial.
   *
   * @return array<string, array<int, string>>
   *   The same groups, re-keyed in GROUP_ORDER's sequence. Any group
   *   present in $groups but absent from GROUP_ORDER (shouldn't
   *   happen for the five known Objektart values) is appended at the
   *   end rather than silently dropped, so a future data addition
   *   fails safe instead of disappearing.
   */
  private function orderGroups(array $groups, string $propertyType): array {
    $order = self::GROUP_ORDER[$propertyType] ?? [];

    $ordered = [];
    foreach ($order as $groupLabel) {
      if (isset($groups[$groupLabel])) {
        $ordered[$groupLabel] = $groups[$groupLabel];
        unset($groups[$groupLabel]);
      }
    }

    return $ordered + $groups;
  }

}