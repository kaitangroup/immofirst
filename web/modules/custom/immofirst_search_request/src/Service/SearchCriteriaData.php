<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Service;

/**
 * Small text-normalization utility for Step 3's criteria groups.
 *
 * This class previously also held a hardcoded groups() array (Step 3's
 * checkbox groups/options, transcribed from Tab.2.1). That data now
 * lives in the "search_criteria" taxonomy vocabulary instead — see
 * _immofirst_search_request_criteria_term_definitions() in
 * immofirst_search_request.install (the source of truth) and
 * SearchCriteriaTermRepository (which loads it for the wizard).
 * machineKey() below is unrelated to that removed data and is still
 * used as-is, purely to turn a group's Group-field label into a
 * stable PHP array key for the Step 3 render array.
 */
final class SearchCriteriaData {

  /**
   * Converts a human label to a stable machine key (for array keys).
   *
   * E.g. "Mit Wallbox / E-Ladestation" -> "mit_wallbox_e_ladestation".
   */
  public static function machineKey(string $label): string {
    $key = mb_strtolower($label);
    $key = str_replace(
      ['ä', 'ö', 'ü', 'ß'],
      ['ae', 'oe', 'ue', 'ss'],
      $key
    );
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

    return trim($key, '_');
  }

}