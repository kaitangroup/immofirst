<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the "Suchauftrag erstellt!" confirmation page (Step 5 success).
 *
 * Note: injected as $nodeStorageManager rather than $entityTypeManager —
 * ControllerBase already declares its own (non-readonly) protected
 * $entityTypeManager property with a lazy-loading entityTypeManager()
 * accessor, so reusing that name here with constructor-promoted
 * `readonly` would collide with the parent class's declaration.
 */
final class ThankYouController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $nodeStorageManager,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the confirmation page render array.
   *
   * The created node's ID is passed as a query argument by the wizard's
   * final AJAX redirect (?nid=123) so we can show the reference number
   * and a couple of node-derived details without re-touching TempStore.
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $nid = $request?->query->get('nid');

    $node = NULL;
    if (is_numeric($nid)) {
      $node = $this->nodeStorageManager->getStorage('node')->load((int) $nid);
    }

    $referenceNumber = $node
      ? sprintf('SA-%s-%06d', date('Y'), $node->id())
      : NULL;

    return [
      '#theme' => 'search_request_thank_you',
      '#reference_number' => $referenceNumber,
      '#node' => $node,
      '#attached' => [
        'library' => ['immofirst_search_request/wizard'],
      ],
    ];
  }

}
