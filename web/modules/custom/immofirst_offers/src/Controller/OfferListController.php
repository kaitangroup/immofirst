<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\immofirst_offers\NotificationMailer;
use Drupal\immofirst_offers\OfferWorkflow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin list of provider offers (/admin/content/angebote).
 */
final class OfferListController extends ControllerBase {

  public function __construct(
    private readonly OfferWorkflow $workflow,
    private readonly NotificationMailer $mailer,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('immofirst_offers.workflow'),
      $container->get('immofirst_offers.mailer'),
      $container->get('date.formatter'),
    );
  }

  public function list(Request $request): array {
    $labels = OfferWorkflow::statusLabels();
    $filter = (string) $request->query->get('status', OfferWorkflow::STATUS_PENDING);
    if ($filter !== 'all' && !isset($labels[$filter])) {
      $filter = OfferWorkflow::STATUS_PENDING;
    }

    $storage = $this->entityTypeManager()->getStorage('webform_submission');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('webform_id', OfferWorkflow::WEBFORM_ID)
      ->condition('in_draft', 0)
      ->sort('created', 'DESC')
      ->pager(50);
    if ($filter !== 'all') {
      $query->condition('offer_status', $filter);
    }

    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $submission) {
      /** @var \Drupal\webform\WebformSubmissionInterface $submission */
      $node = $this->workflow->searchRequest($submission);
      $rows[] = [
        $this->dateFormatter->format((int) $submission->getCreatedTime(), 'custom', 'd.m.Y H:i'),
        $node
          ? Link::fromTextAndUrl($node->get('field_reference_number')->value ?: $node->label(), $node->toUrl())
          : $this->t('— kein Suchauftrag —'),
        $this->mailer->providerEmail($submission) ?? '—',
        $labels[$this->workflow->status($submission)] ?? '—',
        Link::createFromRoute($this->t('Prüfen'), 'immofirst_offers.review', ['webform_submission' => $submission->id()]),
      ];
    }

    $tabs = [];
    foreach ([OfferWorkflow::STATUS_PENDING, OfferWorkflow::STATUS_APPROVED, OfferWorkflow::STATUS_SENT, 'all'] as $key) {
      $title = $key === 'all' ? $this->t('Alle') : $labels[$key];
      $tabs[] = $key === $filter
        ? ['#markup' => '<strong>' . $title . '</strong>']
        : Link::fromTextAndUrl($title, Url::fromRoute('immofirst_offers.list', [], ['query' => ['status' => $key]]))->toRenderable();
    }

    return [
      'filter' => ['#theme' => 'item_list', '#items' => $tabs, '#attributes' => ['class' => ['inline']]],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Eingang'),
          $this->t('Suchauftrag'),
          $this->t('Anbieter'),
          $this->t('Status'),
          $this->t('Aktion'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Keine Angebote.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
