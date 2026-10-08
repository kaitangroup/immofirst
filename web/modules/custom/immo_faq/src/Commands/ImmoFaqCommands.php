<?php

namespace Drupal\immo_faq\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;

/**
 * A Drush commandfile for importing FAQ items.
 */
class ImmoFaqCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs an ImmoFaqCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * Imports FAQs from json file.
   */
  #[CLI\Command(name: 'immo-faq:import', aliases: ['ifi'])]
  #[CLI\Usage(name: 'immo-faq:import', description: 'Import FAQ categories and items from data/faq.json.')]
  public function import() {
    $this->output()->writeln('Importing FAQ content...');

    $module_handler = \Drupal::service('module_handler');
    $module_path = $module_handler->getModule('immo_faq')->getPath();
    $json_file = DRUPAL_ROOT . '/' . $module_path . '/data/faq.json';

    if (!file_exists($json_file)) {
      $this->logger()->error("FAQ JSON file not found at {$json_file}");
      return;
    }

    $content = file_get_contents($json_file);
    $data = json_decode($content, TRUE);

    if (empty($data)) {
      $this->logger()->error('FAQ JSON data is empty or invalid.');
      return;
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $node_storage = $this->entityTypeManager->getStorage('node');

    $created_cats = [];
    $created_count = 0;
    $updated_count = 0;
    $skipped_count = 0;
    $error_count = 0;

    foreach ($data as $item) {
      try {
        $cat_machine = $item['category'];
        $cat_label = $item['category_label'];
        $cat_desc = $item['category_desc'] ?? '';
        $question = $item['question'];
        $answer = $item['answer'];
        $weight = $item['weight'] ?? 0;

        // Find or create term.
        $terms = $term_storage->loadByProperties([
          'vid' => 'faq_category',
          'name' => $cat_label,
        ]);
        $term = reset($terms);

        if (!$term) {
          $term = Term::create([
            'vid' => 'faq_category',
            'name' => $cat_label,
            'description' => [
              'value' => $cat_desc,
              'format' => 'basic_html',
            ],
            'weight' => count($created_cats),
          ]);
          $term->save();
          $created_cats[$cat_label] = $term->id();
          $this->output()->writeln("<info>[OK] Created category: {$cat_label}</info>");
        }

        $term_id = $term->id();

        // Check if FAQ node already exists by title and category.
        $existing_nodes = $node_storage->loadByProperties([
          'type' => 'faq',
          'title' => $question,
        ]);

        $node = NULL;
        foreach ($existing_nodes as $existing) {
          if ($existing->hasField('field_faq_category') && !$existing->get('field_faq_category')->isEmpty()) {
            if ($existing->get('field_faq_category')->target_id == $term_id) {
              $node = $existing;
              break;
            }
          }
        }

        if ($node) {
          // Update existing node.
          $node->set('body', [
            'value' => $answer,
            'format' => 'basic_html',
          ]);
          $node->set('field_faq_weight', $weight);
          $node->setPublished();
          $node->save();
          $updated_count++;
          $this->output()->writeln("<comment>[UPDATED] {$question}</comment>");
        }
        else {
          // Create new node.
          $node = $node_storage->create([
            'type' => 'faq',
            'title' => $question,
            'body' => [
              'value' => $answer,
              'format' => 'basic_html',
            ],
            'field_faq_category' => $term_id,
            'field_faq_weight' => $weight,
            'status' => 1,
          ]);
          $node->save();
          $created_count++;
          $this->output()->writeln("<info>[OK] Created FAQ: {$question}</info>");
        }
      }
      catch (\Exception $e) {
        $error_count++;
        $this->logger()->error($e->getMessage());
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Import completed successfully!</info>');
    $this->output()->writeln("Created: {$created_count}");
    $this->output()->writeln("Updated: {$updated_count}");
    $this->output()->writeln("Errors: {$error_count}");
  }

}
