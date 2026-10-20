<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\immofirst_geosearch\GermanLocationLookup;
use Drupal\immofirst_geosearch\LocationGeocoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON suggestions for "Ort oder PLZ" (homepage search + wizard).
 *
 * Response: {"status": "ok"|"unavailable"|"limited",
 *            "items": [{"value": "80331 München", "label": "80331 München"}]}
 * "label" is HTML-escaped (Drupal's autocomplete renders it as HTML).
 * Successful responses are cacheable (also by the page cache), so a query
 * typed by many visitors costs one provider request. Cache misses are flood-
 * limited per IP to protect the Mapbox quota.
 */
final class LocationSuggestController implements ContainerInjectionInterface {

  private const FLOOD_EVENT = 'immofirst_geosearch.location_suggest';
  private const FLOOD_THRESHOLD = 120;
  private const FLOOD_WINDOW = 600;

  public function __construct(
    private readonly GermanLocationLookup $lookup,
    private readonly LocationGeocoder $locationGeocoder,
    private readonly FloodInterface $flood,
    private readonly KillSwitch $killSwitch,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('immofirst_geosearch.location_lookup'),
      $container->get('immofirst_geosearch.location_geocoder'),
      $container->get('flood'),
      $container->get('page_cache_kill_switch'),
    );
  }

  public function suggest(Request $request): JsonResponse {
    $query = (string) $request->query->get('q', '');

    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_THRESHOLD, self::FLOOD_WINDOW)) {
      return $this->uncacheable('limited');
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);

    $result = $this->lookup->suggest($query);
    if ($result['status'] !== GermanLocationLookup::STATUS_VALID) {
      return $this->uncacheable('unavailable');
    }

    $items = array_map(static fn (array $item): array => [
      'value' => $item['label'],
      'label' => Html::escape($item['label']),
    ], $result['items']);

    $response = new CacheableJsonResponse(['status' => 'ok', 'items' => $items]);
    $metadata = (new CacheableMetadata())
      ->addCacheContexts(['url.query_args:q'])
      ->setCacheMaxAge(86400);
    // Token/country/type changes on the provider invalidate cached answers.
    foreach ($this->locationGeocoder->providers() as $provider) {
      $metadata->addCacheTags($provider->getCacheTags());
    }
    $response->addCacheableDependency($metadata);
    $response->setPublic();
    $response->setMaxAge(86400);
    return $response;
  }

  private function uncacheable(string $status): JsonResponse {
    $this->killSwitch->trigger();
    $response = new JsonResponse(['status' => $status, 'items' => []]);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

}
