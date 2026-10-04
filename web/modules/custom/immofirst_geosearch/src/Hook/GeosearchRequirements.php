<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\immofirst_geosearch\LocationGeocoder;

/**
 * Status report entries for the geosearch.
 *
 * Surfaces the two things that silently disable the Umkreis search: a
 * provider without credentials (settings.php / MAPBOX_ACCESS_TOKEN not set
 * on this environment) and nodes still waiting for the backfill.
 */
final class GeosearchRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly LocationGeocoder $locationGeocoder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];

    $providers = $this->locationGeocoder->providers();
    $missing = [];
    foreach ($providers as $provider) {
      $configuration = $provider->get('configuration');
      if ($provider->get('plugin') === 'mapbox' && empty($configuration['accessToken'])) {
        $missing[] = $provider->label();
      }
    }
    $requirements['immofirst_geosearch_provider'] = match (TRUE) {
      $providers === [] => [
        'title' => $this->t('ImmoFirst geosearch provider'),
        'value' => $this->t('Not configured'),
        'description' => $this->t('field_location_coordinates has no Geocoder provider. Import the configuration.'),
        'severity' => RequirementSeverity::Error,
      ],
      $missing !== [] => [
        'title' => $this->t('ImmoFirst geosearch provider'),
        'value' => $this->t('Access token missing'),
        'description' => $this->t('@providers has no access token on this environment, so locations are not geocoded and the Umkreis search falls back to text matching. Set the MAPBOX_ACCESS_TOKEN environment variable (read in settings.php) or set it in settings.local.php.', [
          '@providers' => implode(', ', $missing),
        ]),
        'severity' => RequirementSeverity::Error,
      ],
      default => [
        'title' => $this->t('ImmoFirst geosearch provider'),
        'value' => implode(', ', array_map(static fn ($provider) => $provider->label(), $providers)),
        'severity' => RequirementSeverity::OK,
      ],
    };

    $without = (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', LocationGeocoder::BUNDLE)
      ->exists(LocationGeocoder::SOURCE_FIELD)
      ->notExists(LocationGeocoder::COORDINATES_FIELD)
      ->count()
      ->execute();
    $requirements['immofirst_geosearch_coordinates'] = [
      'title' => $this->t('ImmoFirst geosearch coordinates'),
      'value' => $this->formatPlural($without, '1 search request without coordinates', '@count search requests without coordinates'),
      'description' => $without ? $this->t('These are not found by the Umkreis search. Run <code>drush immofirst-geosearch:geocode</code>; check the "immofirst_geosearch" log for locations that cannot be geocoded.') : '',
      'severity' => $without ? RequirementSeverity::Warning : RequirementSeverity::OK,
    ];

    return $requirements;
  }

}
