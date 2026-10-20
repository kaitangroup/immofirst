<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
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
 * - "ort" is verified first (GermanLocationLookup::verify()): only a
 *   German postcode/place label as offered by the autocomplete is used as
 *   the search centre.
 * - "ort" is not a valid location (typed without picking a suggestion,
 *   unknown or fabricated): no results, and the empty text explains that a
 *   suggestion must be selected (FLAG_INVALID, see the .module file).
 * - Provider unavailable: the existing text match stays in place, without a
 *   radius, with a visible notice (FLAG_UNAVAILABLE) — never silently.
 *   Not cached, so the radius search resumes once the provider is back.
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

  /**
   * $view->element key holding FLAG_INVALID / FLAG_UNAVAILABLE.
   */
  public const STATE_KEY = '#immofirst_geosearch_location';
  public const FLAG_INVALID = 'invalid';
  public const FLAG_UNAVAILABLE = 'unavailable';

  public function __construct(
    private readonly LocationGeocoder $locationGeocoder,
    private readonly GermanLocationLookup $lookup,
    private readonly KillSwitch $pageCacheKillSwitch,
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

    $check = $this->lookup->verify($location);
    if ($check['status'] === GermanLocationLookup::STATUS_UNAVAILABLE) {
      $view->element[self::STATE_KEY] = self::FLAG_UNAVAILABLE;
      $view->element['#cache']['max-age'] = 0;
      $this->pageCacheKillSwitch->trigger();
      return;
    }
    if ($check['status'] !== GermanLocationLookup::STATUS_VALID) {
      $view->element[self::STATE_KEY] = self::FLAG_INVALID;
      return;
    }
    // Same text the provider verified: resolves (cached) to the very same
    // first result, i.e. the selected place.
    $center = $this->locationGeocoder->resolveSearchCenter($check['label']);
    if ($center === NULL) {
      $view->element[self::STATE_KEY] = self::FLAG_UNAVAILABLE;
      $view->element['#cache']['max-age'] = 0;
      $this->pageCacheKillSwitch->trigger();
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
