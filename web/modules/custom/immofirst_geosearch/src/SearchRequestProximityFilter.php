<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\geofield\Plugin\views\filter\GeofieldProximityFilter;
use Drupal\views\Plugin\ViewsHandlerManager;
use Drupal\views\ViewExecutable;

/**
 * Applies the homepage "Umkreis" as a real distance filter.
 *
 * Runs from hook_views_pre_build(), i.e. after every hook_views_pre_view()
 * (immofirst_search_request's included) has settled the exposed input and
 * before the query is built. Only this execution's handler instances are
 * changed; the View config is not.
 *
 * - No "ort": nothing happens — the radius has no centre, so no radius
 *   filtering at all.
 * - "ort" geocodes: Geofield's own proximity filter (Haversine in SQL on the
 *   stored coordinates) is added, and the plain text match on
 *   field_location is dropped, since "within 25 km of 80331" must not also
 *   require the text "80331".
 * - "ort" doesn't geocode (unknown place, provider down, no token): the
 *   existing text match stays in place, unchanged, and the failure is
 *   logged by LocationGeocoder.
 */
final class SearchRequestProximityFilter {

  public const VIEW_ID = 'search_requests';
  public const DISPLAY_ID = 'block_1';
  public const LOCATION_IDENTIFIER = 'ort';
  public const RADIUS_IDENTIFIER = 'radius';

  /**
   * The existing exposed "ort" text filter in views.view.search_requests.
   */
  private const LOCATION_TEXT_FILTER_ID = 'field_location_value';

  private const PROXIMITY_FILTER_ID = 'field_location_coordinates_proximity';

  public function __construct(
    private readonly LocationGeocoder $locationGeocoder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ViewsHandlerManager $filterManager,
  ) {}

  public function apply(ViewExecutable $view): void {
    if ($view->id() !== self::VIEW_ID || $view->current_display !== self::DISPLAY_ID) {
      return;
    }

    $input = $view->getExposedInput();
    $location = $input[self::LOCATION_IDENTIFIER] ?? '';
    if (!is_string($location) || trim($location) === '') {
      return;
    }

    $center = $this->locationGeocoder->resolveSearchCenter($location);
    if ($center === NULL) {
      return;
    }

    $info = [
      'id' => self::PROXIMITY_FILTER_ID,
      'table' => 'node__' . LocationGeocoder::COORDINATES_FIELD,
      'field' => LocationGeocoder::COORDINATES_FIELD . '_proximity',
      'plugin_id' => 'geofield_proximity_filter',
      'operator' => '<=',
      'value' => [
        'value' => (string) $this->radius($input[self::RADIUS_IDENTIFIER] ?? NULL),
        'min' => '',
        'max' => '',
      ],
      'units' => 'GEOFIELD_KILOMETERS',
      'source' => 'geofield_manual_origin',
      'source_configuration' => [
        'origin' => ['lat' => $center['lat'], 'lon' => $center['lon']],
      ],
      'exposed' => FALSE,
    ];
    $handler = $this->filterManager->getHandler($info);
    if (!$handler instanceof GeofieldProximityFilter) {
      // Field or Geofield views data missing (config not imported yet):
      // keep the text match rather than breaking the listing.
      return;
    }

    // Swap the handlers on this executable only. Display options are
    // already turned into handler instances at this point, and changing
    // them would also change the View entity cached for the rest of the
    // request — so the View config and later executions stay untouched.
    $view->initHandlers();
    $handler->init($view, $view->display_handler, $info);
    unset($view->filter[self::LOCATION_TEXT_FILTER_ID]);
    $view->filter[self::PROXIMITY_FILTER_ID] = $handler;
  }

  /**
   * The requested radius in km, limited to the configured values.
   *
   * A location without a usable radius (e.g. a hand-written ?ort=80331 URL)
   * gets the default radius, which is also what the homepage form
   * preselects.
   */
  private function radius(mixed $value): int {
    $settings = $this->configFactory->get('immofirst_geosearch.settings');
    $allowed = array_map('intval', (array) $settings->get('allowed_radii'));
    $radius = is_scalar($value) ? (int) $value : 0;
    return in_array($radius, $allowed, TRUE) ? $radius : (int) $settings->get('default_radius');
  }

}
