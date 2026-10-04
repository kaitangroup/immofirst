<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch\Drush\Commands;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\immofirst_geosearch\LocationGeocoder;
use Drupal\immofirst_geosearch\SearchRequestCoordinatesBackfill;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the ImmoFirst geosearch.
 */
final class GeosearchCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly SearchRequestCoordinatesBackfill $backfill,
    private readonly LocationGeocoder $locationGeocoder,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Geocodes "Ort oder PLZ" into coordinates for existing Search Requests.
   */
  #[CLI\Command(name: 'immofirst-geosearch:geocode', aliases: ['igeo'])]
  #[CLI\Option(name: 'force', description: 'Re-geocode nodes that already have valid coordinates (existing coordinates are kept if geocoding fails).')]
  #[CLI\Option(name: 'batch-size', description: 'Nodes per batch step. Defaults to immofirst_geosearch.settings:batch_size.')]
  #[CLI\Usage(name: 'drush immofirst-geosearch:geocode', description: 'Geocode every search_request node that has no valid coordinates yet.')]
  #[CLI\Usage(name: 'drush igeo --force', description: 'Re-geocode all search_request nodes, e.g. after changing the provider country.')]
  public function geocode(array $options = ['force' => FALSE, 'batch-size' => self::REQ]): int {
    if ($this->locationGeocoder->providers() === []) {
      $this->logger()->error('No Geocoder provider is configured on field_location_coordinates (or the provider config is missing). Import the configuration first.');
      return self::EXIT_FAILURE;
    }

    $nids = $this->backfill->candidateIds();
    if ($nids === []) {
      $this->logger()->success('No search_request nodes with a location found; nothing to do.');
      return self::EXIT_SUCCESS;
    }

    $size = (int) ($options['batch-size'] ?: $this->configFactory->get('immofirst_geosearch.settings')->get('batch_size'));
    $size = max(1, $size);
    $force = (bool) $options['force'];

    $batch = (new BatchBuilder())
      ->setTitle(dt('Geocoding search request locations'))
      ->setFinishCallback([SearchRequestCoordinatesBackfill::class, 'batchFinished']);
    foreach (array_chunk($nids, $size) as $chunk) {
      $batch->addOperation([SearchRequestCoordinatesBackfill::class, 'batchProcess'], [$chunk, $force]);
    }

    $this->logger()->notice(dt('Checking @count search_request node(s) in batches of @size.', [
      '@count' => count($nids),
      '@size' => $size,
    ]));
    batch_set($batch->toArray());
    drush_backend_batch_process();

    return self::EXIT_SUCCESS;
  }

}
