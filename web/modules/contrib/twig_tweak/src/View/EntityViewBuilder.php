<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\View;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Entity view builder.
 */
final readonly class EntityViewBuilder {

  /**
   * {@selfdoc}
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Builds a render array for a given entity.
   */
  public function build(EntityInterface $entity, string $view_mode = 'full', ?string $langcode = NULL, bool $check_access = TRUE): array {
    $build = [];
    $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);
    $access = $check_access ? $entity->access('view', NULL, TRUE) : AccessResult::allowed();
    if ($access->isAllowed()) {
      $build = $this->entityTypeManager
        ->getViewBuilder($entity->getEntityTypeId())
        ->view($entity, $view_mode, $langcode);
    }
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->addCacheableDependency($entity)
      ->applyTo($build);
    return $build;
  }

}
