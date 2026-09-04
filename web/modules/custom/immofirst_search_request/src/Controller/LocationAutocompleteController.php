<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Backs the "Standort" autocomplete field in Step 3 (Suchdetails).
 *
 * A lightweight static city list rather than a Views/geocoding backend —
 * enough to give the field real autocomplete behavior without pulling in
 * an external service dependency the spec didn't ask for.
 */
final class LocationAutocompleteController extends ControllerBase {

  /**
   * @var string[]
   */
  private const CITIES = [
    'Berlin', 'Hamburg', 'München', 'Köln', 'Frankfurt am Main', 'Stuttgart',
    'Düsseldorf', 'Leipzig', 'Dortmund', 'Essen', 'Bremen', 'Dresden',
    'Hannover', 'Nürnberg', 'Duisburg', 'Bochum', 'Wuppertal', 'Bielefeld',
    'Bonn', 'Münster', 'Mannheim', 'Karlsruhe', 'Augsburg', 'Wiesbaden',
    'Mönchengladbach', 'Gelsenkirchen', 'Braunschweig', 'Chemnitz', 'Kiel',
    'Aachen', 'Halle (Saale)', 'Magdeburg', 'Freiburg im Breisgau', 'Krefeld',
    'Lübeck', 'Oberhausen', 'Erfurt', 'Mainz', 'Rostock', 'Kassel',
    'Hagen', 'Potsdam', 'Saarbrücken', 'Hamm', 'Mülheim an der Ruhr',
    'Ludwigshafen am Rhein', 'Oldenburg', 'Leverkusen', 'Osnabrück', 'Solingen',
  ];

  public function autocomplete(Request $request): JsonResponse {
    $query = trim((string) $request->query->get('q', ''));
    $results = [];

    if ($query !== '') {
      foreach (self::CITIES as $city) {
        if (mb_stripos($city, $query) === 0) {
          $results[] = ['value' => $city, 'label' => $city];
        }
      }
      // Fall back to substring match if no prefix matches were found.
      if (empty($results)) {
        foreach (self::CITIES as $city) {
          if (mb_stripos($city, $query) !== FALSE) {
            $results[] = ['value' => $city, 'label' => $city];
          }
        }
      }
    }

    return new JsonResponse(array_slice($results, 0, 10));
  }

}
