<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\immofirst_offers\OfferWorkflow;
use Drupal\immofirst_offers\WorkflowResult;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review one provider offer and run Approve / Send on it.
 *
 * Approve and Send are separate buttons; each is only offered in the state
 * it applies to, and OfferWorkflow re-checks the state on a fresh copy
 * under a lock, so a stale page, a double click or a crafted POST can't
 * skip a step or send twice. Form API adds the CSRF form token.
 */
final class OfferReviewForm extends FormBase {

  public function __construct(
    private readonly OfferWorkflow $workflow,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('immofirst_offers.workflow'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'immofirst_offers_review';
  }

  /**
   * Route access: only offer_to_search_request submissions.
   *
   * The permission itself is checked by the route's _permission
   * requirement; this keeps other Webforms' submissions out of reach by
   * changing the ID in the URL.
   */
  public static function access(WebformSubmissionInterface $webform_submission): AccessResultInterface {
    return AccessResult::allowedIf(OfferWorkflow::isOffer($webform_submission))
      ->addCacheableDependency($webform_submission);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL): array {
    $form_state->set('sid', (int) $webform_submission->id());
    $status = $this->workflow->status($webform_submission);
    $node = $this->workflow->searchRequest($webform_submission);

    $form['meta'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Status: <strong>@status</strong>', ['@status' => OfferWorkflow::statusLabels()[$status] ?? '—']),
        $node
          ? $this->t('Suchauftrag: @link (@title)', [
            '@link' => Link::fromTextAndUrl($node->get('field_reference_number')->value ?: $node->id(), $node->toUrl())->toString(),
            '@title' => $node->label(),
          ])
          : $this->t('Suchauftrag: — keiner zugeordnet —'),
      ],
    ];

    // The offer exactly as Webform renders it (all elements as currently
    // configured in the Webform UI, files included).
    $form['offer'] = $this->entityTypeManager
      ->getViewBuilder('webform_submission')
      ->view($webform_submission, 'html');

    $form['actions'] = ['#type' => 'actions'];
    if ($status === OfferWorkflow::STATUS_PENDING) {
      $form['actions']['approve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Freigeben'),
        '#submit' => ['::approve'],
        '#button_type' => 'primary',
      ];
    }
    if ($status === OfferWorkflow::STATUS_APPROVED) {
      $form['actions']['send'] = [
        '#type' => 'submit',
        '#value' => $this->t('Senden'),
        '#submit' => ['::send'],
        '#button_type' => 'primary',
        '#suffix' => '<p>' . $this->t('Leitet das Angebot an die E-Mail-Adresse des Suchenden weiter und bestätigt dem Anbieter den Versand (E-Mail 4.4).') . '</p>',
      ];
    }
    $form['actions']['back'] = Link::createFromRoute($this->t('Zurück zur Liste'), 'immofirst_offers.list')->toRenderable();
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function approve(array &$form, FormStateInterface $form_state): void {
    $this->report($this->workflow->approve($this->submission($form_state)));
  }

  public function send(array &$form, FormStateInterface $form_state): void {
    $this->report($this->workflow->send($this->submission($form_state)));
  }

  private function submission(FormStateInterface $form_state): WebformSubmissionInterface {
    // Always the submission from the access-checked route, never a value
    // posted by the browser.
    return $this->entityTypeManager->getStorage('webform_submission')->load($form_state->get('sid'));
  }

  private function report(WorkflowResult $result): void {
    match (TRUE) {
      !$result->success => $this->messenger()->addError($result->message),
      $result->warning => $this->messenger()->addWarning($result->message),
      default => $this->messenger()->addStatus($result->message),
    };
  }

}
