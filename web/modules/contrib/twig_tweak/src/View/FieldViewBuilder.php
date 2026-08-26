<?php

declare(strict_types=1);

namespace Drupal\twig_tweak\View;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;

/**
 * Field view builder.
 */
final readonly class FieldViewBuilder {

  /**
   * {@selfdoc}
   */
  public function __construct(private EntityRepositoryInterface $entityRepository) {}

  /**
   * Returns the render array for a single entity field.
   *
   * @see \Drupal\Core\Entity\EntityViewBuilderInterface::viewField()
   */
  public function build(
    EntityInterface $entity,
    string $field_name,
    string|array $view_mode = 'full',
    ?string $langcode = NULL,
    ?bool $check_access = TRUE,
  ): array {
    $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);

    if (!isset($entity->{$field_name})) {
      throw new \InvalidArgumentException(\sprintf('Field "%s" does not exist in "%s" entity type.', $field_name, $entity->getEntityTypeId()));
    }

    $access = $check_access ? $entity->access('view', NULL, TRUE) : AccessResult::allowed();
    $build = $access->isAllowed() ? $entity->{$field_name}->view($view_mode) : [];

    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($access)
      ->addCacheableDependency($entity)
      ->applyTo($build);

    return $build;
  }

}
