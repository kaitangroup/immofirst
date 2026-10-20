<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Geocoder\Collection;
use Geocoder\Location;
use Geocoder\Provider\Mapbox\Model\MapboxAddress;
use Psr\Log\LoggerInterface;

/**
 * German "Ort oder PLZ" suggestions and server-side verification.
 *
 * Uses the same Geocoder provider entity (Mapbox) as geocoder_field and the
 * homepage radius search — see LocationGeocoder::providers() — so country
 * ("de"), language ("de"), token and the geocoder cache are shared. No
 * second geocoding service and no extra package.
 *
 * Every suggestion is a canonical label built from one provider result:
 *   - postcode:        "80331 München"
 *   - place/locality:  "Neustadt an der Aisch, Bayern" ("Berlin" when the
 *                      place is its own Bundesland)
 * A submitted value is valid only when geocoding that exact label returns,
 * as its FIRST result, a German postcode/place/locality whose label is the
 * same text. geocoder_field also stores the first result's coordinates for
 * that text, so the saved coordinates and the homepage search centre are
 * guaranteed to be the place the user selected; client coordinates are
 * never accepted. The geocoder module caches results per text, so
 * verification and the save-time geocoding cost at most one API request.
 */
final class GermanLocationLookup {

  public const STATUS_VALID = 'valid';
  public const STATUS_INVALID = 'invalid';
  public const STATUS_UNAVAILABLE = 'unavailable';

  /**
   * Mapbox result types offered and accepted (no streets, districts, ...).
   */
  private const TYPES = ['postcode', 'place', 'locality'];

  public const MAX_LENGTH = 100;

  public function __construct(
    private readonly LocationGeocoder $locationGeocoder,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Suggestions for an autocomplete query.
   *
   * @return array{status: string, items: array<int, array{label: string, lat: float, lon: float}>}
   *   status is STATUS_VALID (items may be empty: nothing found) or
   *   STATUS_UNAVAILABLE (provider error, no token, ...).
   */
  public function suggest(string $text): array {
    $text = self::normalize($text);
    if (mb_strlen($text) < 2 || mb_strlen($text) > self::MAX_LENGTH) {
      return ['status' => self::STATUS_VALID, 'items' => []];
    }
    try {
      $collection = $this->query($text);
    }
    catch (\Throwable $e) {
      $this->logUnavailable($e);
      return ['status' => self::STATUS_UNAVAILABLE, 'items' => []];
    }

    $items = [];
    foreach ($collection ?? [] as $location) {
      $item = $this->toItem($location);
      if ($item !== NULL) {
        $items[mb_strtolower($item['label'])] ??= $item;
      }
    }
    return ['status' => self::STATUS_VALID, 'items' => array_values($items)];
  }

  /**
   * Verifies a submitted location (see class docs).
   *
   * @return array{status: string, label?: string, lat?: float, lon?: float}
   *   With STATUS_VALID, 'label' is the canonical spelling to store/search.
   */
  public function verify(string $text): array {
    $text = self::normalize($text);
    if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
      return ['status' => self::STATUS_INVALID];
    }
    try {
      $collection = $this->query($text);
    }
    catch (\Throwable $e) {
      $this->logUnavailable($e);
      return ['status' => self::STATUS_UNAVAILABLE];
    }
    if ($collection === NULL || $collection->isEmpty()) {
      return ['status' => self::STATUS_INVALID];
    }

    $item = $this->toItem($collection->first());
    if ($item === NULL || mb_strtolower($item['label']) !== mb_strtolower($text)) {
      return ['status' => self::STATUS_INVALID];
    }
    return ['status' => self::STATUS_VALID] + $item;
  }

  /**
   * Geocodes through the configured provider plugin, without swallowing
   * errors (the Geocoder service would turn them into "not found").
   *
   * Same plugin call and cache entry as geocoder_field / LocationGeocoder.
   *
   * @throws \Throwable
   *   On provider errors (network, HTTP, invalid token, no provider).
   */
  private function query(string $text): ?Collection {
    $providers = $this->locationGeocoder->providers();
    if ($providers === []) {
      throw new \RuntimeException('No Geocoder provider is configured on field_location_coordinates.');
    }
    $result = $providers[0]->getPlugin()->geocode($text);
    return $result instanceof Collection ? $result : NULL;
  }

  /**
   * @return array{label: string, lat: float, lon: float}|null
   *   NULL for results outside Germany, of another type, or incomplete.
   */
  private function toItem(Location $location): ?array {
    if (!$location instanceof MapboxAddress || $location->getCountry()?->getCode() !== 'DE') {
      return NULL;
    }
    $types = $location->getResultType();
    $name = trim((string) $location->getStreetName());
    $coordinates = $location->getCoordinates();
    if ($name === '' || $coordinates === NULL || !array_intersect(self::TYPES, $types)) {
      return NULL;
    }

    if (in_array('postcode', $types, TRUE)) {
      $city = trim((string) $location->getLocality());
      if ($city === '') {
        return NULL;
      }
      $label = $name . ' ' . $city;
    }
    else {
      $levels = $location->getAdminLevels();
      $region = $levels->has(2) ? trim($levels->get(2)->getName()) : '';
      $label = $region !== '' && $region !== $name ? $name . ', ' . $region : $name;
    }

    return ['label' => $label, 'lat' => $coordinates->getLatitude(), 'lon' => $coordinates->getLongitude()];
  }

  private function logUnavailable(\Throwable $e): void {
    // Geocoder's HTTP exceptions include the request URL, which carries the
    // token as a query parameter — never log it.
    $this->logger->error('Location lookup unavailable: @class: @message', [
      '@class' => get_class($e),
      '@message' => preg_replace('/access_token=[^&\s"\']+/', 'access_token=[redacted]', $e->getMessage()),
    ]);
  }

  private static function normalize(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
