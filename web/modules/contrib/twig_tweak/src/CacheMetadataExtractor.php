<?php

declare(strict_types=1);

namespace Drupal\twig_tweak;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Element;

/**
 * The cache metadata extractor.
 */
final readonly class CacheMetadataExtractor {

  /**
   * Extracts cache metadata from object or render array.
   *
   * @param \Drupal\Core\Cache\CacheableDependencyInterface|array $input
   *   The cacheable object or render array.
   *
   * @phpstan-return array{"#cache": array{'contexts': list<non-empty-string>, 'tags': list<non-empty-string>, 'max-age': int}}
   *   A render array with extracted cache metadata.
   */
  public function extractCacheMetadata(CacheableDependencyInterface|array $input): array {
    $build = [];
    $cache_metadata = $input instanceof CacheableDependencyInterface ?
      CacheableMetadata::createFromObject($input) : self::extractFromArray($input);
    $cache_metadata->applyTo($build);
    return $build;
  }

  /**
   * Extracts cache metadata from renders array.
   */
  private static function extractFromArray(array $build): CacheableMetadata {
    $cache_metadata = CacheableMetadata::createFromRenderArray($build);
    $keys = Element::children($build);
    foreach (\array_intersect_key($build, \array_flip($keys)) as $item) {
      $cache_metadata->addCacheableDependency(self::extractFromArray($item));
    }
    return $cache_metadata;
  }

}
