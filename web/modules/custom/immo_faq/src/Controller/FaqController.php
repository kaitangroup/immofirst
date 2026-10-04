<?php

namespace Drupal\immo_faq\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the FAQ page.
 */
class FaqController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a FaqController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns the FAQ page render array.
   */
  public function content() {
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Load all FAQ category terms sorted by weight.
    $terms = $term_storage->loadByProperties(['vid' => 'faq_category']);
    // Sort terms by weight.
    uasort($terms, function ($a, $b) {
      return ($a->get('weight')->value ?? 0) <=> ($b->get('weight')->value ?? 0);
    });

    $categories_data = [];

    // Map category IDs to specific SVG icons / icon classes matching design.
    $icon_map = [
      'Allgemeine Fragen' => 'question',
      'Ich suche eine Immobilie' => 'house',
      'Ich biete eine Immobilie an' => 'building',
    ];

    foreach ($terms as $term) {
      $term_id = $term->id();
      $term_name = $term->getName();
      $term_desc = '';
      if ($term->hasField('description') && !$term->get('description')->isEmpty()) {
        $term_desc = $term->get('description')->value;
      }

      $icon_type = $icon_map[$term_name] ?? 'question';

      // Load FAQ nodes for this term.
      $nids = $node_storage->getQuery()
        ->condition('type', 'faq')
        ->condition('status', 1)
        ->condition('field_faq_category', $term_id)
        ->sort('field_faq_weight', 'ASC')
        ->accessCheck(TRUE)
        ->execute();

      $nodes = $node_storage->loadMultiple($nids);
      $faqs = [];

      foreach ($nodes as $node) {
        $body = '';
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $body = $node->get('body')->processed;
        }

        $faqs[] = [
          'id' => 'faq-' . $node->id(),
          'question' => $node->label(),
          'answer' => $body,
        ];
      }

      if (!empty($faqs)) {
        $categories_data[] = [
          'title' => $term_name,
          'description' => $term_desc,
          'icon' => $icon_type,
          'items' => $faqs,
        ];
      }
    }

    // Get module base path for SVG images.
    $module_handler = \Drupal::service('module_handler');
    $module_path = '/' . $module_handler->getModule('immo_faq')->getPath();

    return [
      '#theme' => 'immo_faq_page',
      '#categories' => $categories_data,
      '#module_path' => $module_path,
      '#attached' => [
        'library' => [
          'immo_faq/faq',
        ],
      ],
    ];
  }

}
