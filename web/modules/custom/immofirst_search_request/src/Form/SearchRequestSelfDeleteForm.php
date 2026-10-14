<?php

declare(strict_types=1);

namespace Drupal\immofirst_search_request\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\immofirst_search_request\Service\SearchRequestDeletionLink;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Requester's own "Immobiliengesuch löschen" confirmation (from e-mail 4.1/4.5).
 *
 * Reached only through a signed link (SearchRequestDeletionLink). Opening the
 * link shows this confirmation; the request is deleted only when the form is
 * submitted, after the signature has been checked again. The deletion goes
 * through the normal entity API, so hook_node_delete() in immofirst_offers
 * sends the deletion confirmation (e-mail 4.3) once the delete has committed.
 */
final class SearchRequestSelfDeleteForm extends FormBase {

  public function __construct(
    private readonly SearchRequestDeletionLink $deletionLink,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('immofirst_search_request.deletion_link'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'immofirst_search_request_self_delete';
  }

  /**
   * Route access: a valid signature for exactly this search request.
   */
  public static function access(NodeInterface $node, string $hash): AccessResultInterface {
    /** @var \Drupal\immofirst_search_request\Service\SearchRequestDeletionLink $link */
    $link = \Drupal::service('immofirst_search_request.deletion_link');
    // Never cached: the result depends on the signature in the URL.
    return AccessResult::allowedIf($link->verify($node, $hash))->setCacheMaxAge(0);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, string $hash = ''): array {
    $form_state->set('nid', (int) $node->id());
    $form_state->set('hash', $hash);
    $reference = (string) $node->get('field_reference_number')->value;

    $form['#title'] = $this->t('Immobiliengesuch löschen');
    $form['question'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Möchten Sie Ihr Immobiliengesuch %title (Such-ID: %reference) wirklich endgültig löschen?', [
        '%title' => $node->label(),
        '%reference' => $reference !== '' ? $reference : '—',
      ]),
    ];
    $form['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Das Immobiliengesuch wird sofort entfernt und ist danach nicht mehr sichtbar. Zu diesem Immobiliengesuch erhalten Sie anschließend keine Angebote mehr. Dieser Schritt kann nicht rückgängig gemacht werden.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Immobiliengesuch endgültig löschen'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Abbrechen'),
      '#url' => Url::fromRoute('<front>'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Re-check on submit: never trust form state or posted values alone.
    $node = $this->entityTypeManager->getStorage('node')->load($form_state->get('nid'));
    if (!$node instanceof NodeInterface || !$this->deletionLink->verify($node, (string) $form_state->get('hash'))) {
      $this->messenger()->addError($this->t('Dieses Immobiliengesuch konnte nicht gelöscht werden. Der Link ist ungültig oder das Gesuch existiert nicht mehr.'));
      // The front page template prints no status messages; the wizard page does,
      // and "Neues Immobiliengesuch erstellen" is the natural next step (4.3).
      $form_state->setRedirect('immofirst_search_request.wizard');
      return;
    }
    // Tells hook_node_delete() (immofirst_offers) that the requester deleted
    // it themselves, so e-mail 4.3 uses the "auf Ihren Wunsch" wording.
    $this->getRequest()->attributes->set('immofirst_search_request.deleted_by_requester', (int) $node->id());
    $node->delete();
    $this->messenger()->addStatus($this->t('Ihr Immobiliengesuch wurde gelöscht. Sie erhalten in Kürze eine Bestätigung per E-Mail.'));
    $form_state->setRedirect('immofirst_search_request.wizard');
  }

}
