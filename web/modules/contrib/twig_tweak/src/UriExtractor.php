<?php

declare(strict_types=1);

namespace Drupal\twig_tweak;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\Plugin\media\Source\OEmbedInterface;

/**
 * The URI extractor service.
 */
final readonly class UriExtractor {

  /**
   * {@selfdoc}
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns a URI to the file.
   *
   * @param object|null $input
   *   An object that contains the URI.
   *
   * @return non-empty-string|null
   *   A URI that can be used to access the file, or null if the object doesn't
   *   contain a valid URI.
   */
  public function extractUri(?object $input): ?string {
    $entity = $input;
    if ($input instanceof EntityReferenceFieldItemListInterface) {
      if ($item = $input->first()) {
        $entity = $item->entity;
      }
    }
    elseif ($input instanceof EntityReferenceItem) {
      $entity = $input->entity;
    }
    // Drupal doesn't clean up references to deleted entities, so the entity
    // property might be empty even when the field item exists.
    // @see https://www.drupal.org/project/drupal/issues/2723323
    return $entity instanceof ContentEntityInterface ?
      $this->getUriFromEntity($entity) : NULL;
  }

  /**
   * Extracts file URI from content entity.
   */
  private function getUriFromEntity(ContentEntityInterface $entity): ?string {
    if ($entity instanceof MediaInterface) {
      $source = $entity->getSource();
      $value = $source->getSourceFieldValue($entity);
      if ($source instanceof OEmbedInterface) {
        return $value;
      }
      $file = $this->entityTypeManager->getStorage('file')->load($value);
      if ($file) {
        return $file->getFileUri();
      }
    }
    elseif ($entity instanceof FileInterface) {
      return $entity->getFileUri();
    }
    return NULL;
  }

}
