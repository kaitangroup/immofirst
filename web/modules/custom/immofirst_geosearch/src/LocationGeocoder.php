<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\geocoder\GeocoderInterface;
use Drupal\geofield\WktGeneratorInterface;
use Drupal\node\NodeInterface;
use Geocoder\Collection;
use Psr\Log\LoggerInterface;

/**
 * Turns a free-text "Ort oder PLZ" into latitude/longitude.
 *
 * Single place that knows which Geocoder providers are used: they are read
 * from the geocoder_field settings of field_location_coordinates, so node
 * saves (geocoder_field), the backfill and the homepage search centre all
 * geocode through exactly the same provider configuration.
 */
final class LocationGeocoder {

  public const BUNDLE = 'search_request';
  public const SOURCE_FIELD = 'field_location';
  public const COORDINATES_FIELD = 'field_location_coordinates';

  /**
   * Resolved homepage search centres are cached for a week.
   */
  private const CENTER_CACHE_TTL = 604800;

  /**
   * Unresolvable search texts are cached for an hour, so a typo searched
   * repeatedly doesn't hit the provider (and the log) on every request.
   */
  private const FAILED_CENTER_CACHE_TTL = 3600;

  /**
   * Per-request memo of resolved search centres, keyed by normalized text.
   *
   * @var array<string, array{lat: float, lon: float}|null>
   */
  private array $centers = [];

  public function __construct(
    private readonly GeocoderInterface $geocoder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly WktGeneratorInterface $wktGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Geocodes a location text with the configured providers.
   *
   * @return array{lat: float, lon: float}|null
   *   The first match's coordinates, or NULL when nothing (valid) was found.
   *   Provider errors are caught and logged by the Geocoder service itself.
   */
  public function geocode(string $location): ?array {
    $location = self::normalize($location);
    $providers = $this->providers();
    if ($location === '' || $providers === []) {
      return NULL;
    }

    $result = $this->geocoder->geocode($location, $providers);
    if (!$result instanceof Collection || $result->isEmpty()) {
      return NULL;
    }

    $coordinates = $result->first()->getCoordinates();
    if ($coordinates === NULL) {
      return NULL;
    }

    $point = ['lat' => $coordinates->getLatitude(), 'lon' => $coordinates->getLongitude()];
    return self::isValidPoint($point['lat'], $point['lon']) ? $point : NULL;
  }

  /**
   * Resolves the homepage "ort" input to a search centre, with caching.
   *
   * @return array{lat: float, lon: float}|null
   *   NULL when the text can't be geocoded; callers then fall back to the
   *   existing text match instead of applying a radius.
   */
  public function resolveSearchCenter(string $location): ?array {
    $location = self::normalize($location);
    if ($location === '') {
      return NULL;
    }

    $key = mb_strtolower($location);
    if (array_key_exists($key, $this->centers)) {
      return $this->centers[$key];
    }

    $cid = 'immofirst_geosearch:center:' . hash('sha256', $key);
    if ($cached = $this->cache->get($cid)) {
      return $this->centers[$key] = $cached->data;
    }

    $center = $this->geocode($location);
    $now = $this->time->getRequestTime();
    // Tagged with the provider config, so changing e.g. the country
    // restriction or the token invalidates every cached centre.
    $tags = [];
    foreach ($this->providers() as $provider) {
      $tags = array_merge($tags, $provider->getCacheTags());
    }

    if ($center === NULL) {
      $this->logger->warning('Homepage search location "@location" could not be geocoded; the search falls back to the text match on "Ort oder PLZ" without a radius.', [
        '@location' => $location,
      ]);
      $this->cache->set($cid, NULL, $now + self::FAILED_CENTER_CACHE_TTL, $tags);
    }
    else {
      $this->cache->set($cid, $center, $now + self::CENTER_CACHE_TTL, $tags);
    }

    return $this->centers[$key] = $center;
  }

  /**
   * Whether the node already holds usable coordinates.
   */
  public function hasValidCoordinates(NodeInterface $node): bool {
    if (!$node->hasField(self::COORDINATES_FIELD) || $node->get(self::COORDINATES_FIELD)->isEmpty()) {
      return FALSE;
    }
    $item = $node->get(self::COORDINATES_FIELD)->first();
    return self::isValidPoint($item->get('lat')->getValue(), $item->get('lon')->getValue());
  }

  /**
   * Builds the Geofield (WKT) value for a point.
   *
   * @param array{lat: float, lon: float} $point
   */
  public function toGeofieldValue(array $point): string {
    // WKT is "POINT (lon lat)".
    return $this->wktGenerator->wktBuildPoint([$point['lon'], $point['lat']]);
  }

  /**
   * Geocoder provider entities configured on field_location_coordinates.
   *
   * @return \Drupal\geocoder\GeocoderProviderInterface[]
   */
  public function providers(): array {
    $field = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE)[self::COORDINATES_FIELD] ?? NULL;
    if (!$field instanceof ThirdPartySettingsInterface) {
      return [];
    }
    $ids = $field->getThirdPartySetting('geocoder_field', 'providers', []);
    if ($ids === []) {
      return [];
    }

    $providers = $this->entityTypeManager->getStorage('geocoder_provider')->loadMultiple($ids);
    // Keep the configured order: the first provider with a result wins.
    return array_values(array_filter(array_map(static fn ($id) => $providers[$id] ?? NULL, $ids)));
  }

  private static function normalize(string $location): string {
    return trim((string) preg_replace('/\s+/u', ' ', $location));
  }

  private static function isValidPoint(mixed $lat, mixed $lon): bool {
    return is_numeric($lat) && is_numeric($lon)
      && $lat >= -90 && $lat <= 90
      && $lon >= -180 && $lon <= 180
      // 0,0 ("Null Island") is what broken provider responses produce.
      && !((float) $lat === 0.0 && (float) $lon === 0.0);
  }

}
