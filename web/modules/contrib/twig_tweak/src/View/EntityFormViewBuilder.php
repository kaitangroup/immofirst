<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\View;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * {@selfdoc}
 */
final readonly class EntityFormViewBuilder {

  /**
   * {@selfdoc}
   */
  public function __construct(
    private EntityFormBuilderInterface $entityFormBuilder,
    private EntityRepositoryInterface $entityRepository,
  ) {
  }

  /**
   * Gets the built and processed entity form for the given entity type.
   */
  public function build(EntityInterface $entity, string $form_mode = 'default', ?string $langcode = NULL, bool $check_access = TRUE): array {
    $build = [];
    $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);

    $operation = $entity->isNew() ? 'create' : 'update';
    $access = $check_access ? $entity->access($operation, NULL, TRUE) : AccessResult::allowed();
    if ($access->isAllowed()) {
      $build = $this->entityFormBuilder->getForm($entity, $form_mode);
    }

    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->addCacheableDependency($entity)
      ->applyTo($build);

    return $build;
  }

}
