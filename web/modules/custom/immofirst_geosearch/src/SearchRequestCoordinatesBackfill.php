<?php

declare(strict_types=1);

namespace Drupal\immofirst_geosearch;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills field_location_coordinates on existing Search Request nodes.
 *
 * New and edited nodes are geocoded on save by geocoder_field (see the
 * field's third-party settings); this covers nodes saved before the field
 * existed, or whose geocoding failed at the time. Used by
 * `drush immofirst-geosearch:geocode` through the Batch API.
 */
final class SearchRequestCoordinatesBackfill {

  public const RESULT_GEOCODED = 'geocoded';
  public const RESULT_SKIPPED = 'skipped';
  public const RESULT_NO_LOCATION = 'no_location';
  public const RESULT_FAILED = 'failed';

  public function __construct(
    private readonly LocationGeocoder $locationGeocoder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * IDs of every search_request node that has a location.
   *
   * Nodes that already have valid coordinates are skipped per node in
   * process(), which also catches stored-but-invalid coordinates.
   *
   * @return int[]
   */
  public function candidateIds(): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', LocationGeocoder::BUNDLE)
      ->exists(LocationGeocoder::SOURCE_FIELD)
      ->sort('nid')
      ->execute();
    return array_map('intval', array_values($ids));
  }

  /**
   * Geocodes one node unless it already has valid coordinates.
   *
   * @param bool $force
   *   Re-geocode even when valid coordinates exist. On failure, existing
   *   coordinates are kept.
   *
   * @return string
   *   One of the RESULT_* constants.
   */
  public function process(int $nid, bool $force = FALSE): string {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== LocationGeocoder::BUNDLE) {
      return self::RESULT_SKIPPED;
    }
    if (!$force && $this->locationGeocoder->hasValidCoordinates($node)) {
      return self::RESULT_SKIPPED;
    }

    $location = trim((string) $node->get(LocationGeocoder::SOURCE_FIELD)->value);
    if ($location === '') {
      return self::RESULT_NO_LOCATION;
    }

    $point = $this->locationGeocoder->geocode($location);
    if ($point === NULL) {
      $this->logger->warning('Search request @nid: location "@location" could not be geocoded; coordinates left unchanged.', [
        '@nid' => $nid,
        '@location' => $location,
      ]);
      return self::RESULT_FAILED;
    }

    $node->set(LocationGeocoder::COORDINATES_FIELD, $this->locationGeocoder->toGeofieldValue($point));
    // A data fix, not an editorial change: no new revision, and syncing
    // keeps the "changed" timestamp as it was. geocoder_field skips this
    // save, since the location is unchanged and the coordinates are set.
    $node->setNewRevision(FALSE);
    $node->setSyncing(TRUE);
    $node->save();

    return self::RESULT_GEOCODED;
  }

  /**
   * Batch operation: processes one chunk of node IDs.
   *
   * @param int[] $nids
   */
  public static function batchProcess(array $nids, bool $force, array &$context): void {
    /** @var self $backfill */
    $backfill = \Drupal::service('immofirst_geosearch.backfill');
    $context['results'] += [
      self::RESULT_GEOCODED => 0,
      self::RESULT_SKIPPED => 0,
      self::RESULT_NO_LOCATION => 0,
      self::RESULT_FAILED => 0,
      'failed_nids' => [],
    ];

    foreach ($nids as $nid) {
      try {
        $result = $backfill->process((int) $nid, $force);
      }
      catch (\Throwable $e) {
        \Drupal::logger('immofirst_geosearch')->error('Search request @nid: geocoding backfill failed: @message', [
          '@nid' => $nid,
          '@message' => $e->getMessage(),
        ]);
        $result = self::RESULT_FAILED;
      }
      $context['results'][$result]++;
      if ($result === self::RESULT_FAILED) {
        $context['results']['failed_nids'][] = (int) $nid;
      }
    }

    $context['message'] = new TranslatableMarkup('Geocoded @geocoded, skipped @skipped, failed @failed so far.', [
      '@geocoded' => $context['results'][self::RESULT_GEOCODED],
      '@skipped' => $context['results'][self::RESULT_SKIPPED],
      '@failed' => $context['results'][self::RESULT_FAILED],
    ]);
  }

  /**
   * Batch finish callback: reports the totals.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The coordinate backfill did not finish. Run it again: nodes that already have coordinates are skipped.'));
    }

    $summary = new TranslatableMarkup('Search request coordinates: @geocoded geocoded, @skipped skipped (already valid), @no_location without location, @failed failed.', [
      '@geocoded' => $results[self::RESULT_GEOCODED] ?? 0,
      '@skipped' => $results[self::RESULT_SKIPPED] ?? 0,
      '@no_location' => $results[self::RESULT_NO_LOCATION] ?? 0,
      '@failed' => $results[self::RESULT_FAILED] ?? 0,
    ]);
    $messenger->addStatus($summary);
    \Drupal::logger('immofirst_geosearch')->info((string) $summary);

    if (!empty($results['failed_nids'])) {
      $messenger->addWarning(new TranslatableMarkup('Could not geocode node(s) @nids — check "Ort oder PLZ" on these nodes, or the "immofirst_geosearch" / "geocoder" log entries.', [
        '@nids' => implode(', ', $results['failed_nids']),
      ]));
    }
  }

}
