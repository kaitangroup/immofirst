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
 * ImmoFirst_260826.xlsx, now stored as taxonomy_term entities instead
 * of PHP literals (see
 * _immofirst_search_request_criteria_term_definitions() in
 * immofirst_search_request.install for the canonical source data and
 * ordering).
 */
final class SearchCriteriaTermRepository {

  private const VOCABULARY_ID = 'search_criteria';

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
   *   [group label => [term id => term label]], group order and, within
   *   each group, option order both following taxonomy term weight —
   *   i.e. the same shape and ordering
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
    foreach ($ids as $id) {
      $term = $terms[$id] ?? NULL;
      if (!$term instanceof TermInterface) {
        continue;
      }

      $groupLabel = (string) ($term->get('field_group')->value ?? '');
      $groups[$groupLabel][(int) $id] = (string) $term->label();
    }

    return $groups;
  }

}