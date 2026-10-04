<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Pending → Approve → Approved → Send → Sent for provider offers.
 *
 * State lives in the "offer_status" base field this module adds to
 * webform_submission (see immofirst_offers_entity_base_field_info()).
 * The offer's own data stays in the Webform submission; the Search Request
 * is the submission's source entity, which Webform records automatically
 * because the form is embedded on the search_request node page.
 */
final class OfferWorkflow {

  use StringTranslationTrait;

  public const WEBFORM_ID = 'offer_to_search_request';

  public const STATUS_PENDING = 'pending';
  public const STATUS_APPROVED = 'approved';
  public const STATUS_SENT = 'sent';

  public function __construct(
    private readonly NotificationMailer $mailer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LockBackendInterface $lock,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Allowed values for the offer_status field.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   */
  public static function statusLabels(): array {
    return [
      self::STATUS_PENDING => new TranslatableMarkup('Ausstehend'),
      self::STATUS_APPROVED => new TranslatableMarkup('Freigegeben'),
      self::STATUS_SENT => new TranslatableMarkup('Gesendet'),
    ];
  }

  public static function isOffer(WebformSubmissionInterface $submission): bool {
    return $submission->getWebform()?->id() === self::WEBFORM_ID;
  }

  public function status(WebformSubmissionInterface $submission): string {
    return (string) $submission->get('offer_status')->value;
  }

  /**
   * The search_request node this offer was submitted on, if any.
   */
  public function searchRequest(WebformSubmissionInterface $submission): ?NodeInterface {
    $source = $submission->getSourceEntity();
    return $source instanceof NodeInterface && $source->bundle() === 'search_request' ? $source : NULL;
  }

  /**
   * Pending → Approved. Sends nothing.
   */
  public function approve(WebformSubmissionInterface $submission): WorkflowResult {
    return $this->locked($submission, function (WebformSubmissionInterface $submission): WorkflowResult {
      if ($this->status($submission) !== self::STATUS_PENDING) {
        return WorkflowResult::failure($this->t('Nur ausstehende Angebote können freigegeben werden.'));
      }
      $submission->set('offer_status', self::STATUS_APPROVED)->save();
      $this->logger->notice('Offer @sid approved.', ['@sid' => $submission->id()]);
      return WorkflowResult::success($this->t('Das Angebot wurde freigegeben. Es wurde noch nichts versendet.'));
    });
  }

  /**
   * Approved → forward to requester → Sent → e-mail 4.4 to provider.
   *
   * The offer is only marked Sent once the forward to the requester has
   * been accepted by the mail system; if it fails the offer stays
   * Approved so the admin can retry.
   */
  public function send(WebformSubmissionInterface $submission): WorkflowResult {
    return $this->locked($submission, function (WebformSubmissionInterface $submission): WorkflowResult {
      if ($this->status($submission) !== self::STATUS_APPROVED) {
        return WorkflowResult::failure($this->status($submission) === self::STATUS_SENT
          ? $this->t('Dieses Angebot wurde bereits gesendet.')
          : $this->t('Nur freigegebene Angebote können gesendet werden.'));
      }

      $node = $this->searchRequest($submission);
      if (!$node) {
        $this->logger->error('Offer @sid not sent: no search request linked.', ['@sid' => $submission->id()]);
        return WorkflowResult::failure($this->t('Diesem Angebot ist kein Suchauftrag zugeordnet.'));
      }
      if ($this->mailer->requesterEmail($node) === NULL) {
        return WorkflowResult::failure($this->t('Der Suchauftrag hat keine gültige E-Mail-Adresse.'));
      }
      if ($this->mailer->providerEmail($submission) === NULL) {
        return WorkflowResult::failure($this->t('Das Angebot enthält keine gültige Anbieter-E-Mail-Adresse.'));
      }

      if (!$this->attempt(fn () => $this->mailer->forwardOfferToRequester($submission, $node), $submission)) {
        return WorkflowResult::failure($this->t('Das Angebot konnte nicht an den Suchenden gesendet werden und bleibt freigegeben. Details im Protokoll.'));
      }

      $submission->set('offer_status', self::STATUS_SENT)->save();
      $this->logger->notice('Offer @sid sent to the requester of search request @nid.', [
        '@sid' => $submission->id(),
        '@nid' => $node->id(),
      ]);

      if (!$this->attempt(fn () => $this->mailer->offerSent($submission, $node), $submission)) {
        return WorkflowResult::success($this->t('Das Angebot wurde gesendet, aber die Bestätigung (4.4) an den Anbieter ist fehlgeschlagen. Details im Protokoll.'), TRUE);
      }
      return WorkflowResult::success($this->t('Das Angebot wurde an den Suchenden gesendet und der Anbieter benachrichtigt.'));
    });
  }

  /**
   * Runs one mail step; an exception counts as failure and is logged.
   */
  private function attempt(callable $step, WebformSubmissionInterface $submission): bool {
    try {
      return (bool) $step();
    }
    catch (\Throwable $e) {
      $this->logger->error('Mail step for offer @sid failed: @message', [
        '@sid' => $submission->id(),
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
      return FALSE;
    }
  }

  /**
   * Runs a transition under a per-submission lock on a fresh copy.
   *
   * Guards against double clicks / parallel requests acting on the same
   * stale state (e.g. two "Senden" clicks forwarding the offer twice).
   */
  private function locked(WebformSubmissionInterface $submission, callable $transition): WorkflowResult {
    $name = 'immofirst_offers:' . $submission->id();
    if (!$this->lock->acquire($name, 60)) {
      return WorkflowResult::failure($this->t('Dieses Angebot wird gerade bearbeitet. Bitte versuchen Sie es gleich erneut.'));
    }
    try {
      $storage = $this->entityTypeManager->getStorage('webform_submission');
      $storage->resetCache([$submission->id()]);
      $fresh = $storage->load($submission->id());
      if (!$fresh instanceof WebformSubmissionInterface || !self::isOffer($fresh)) {
        return WorkflowResult::failure($this->t('Angebot nicht gefunden.'));
      }
      return $transition($fresh);
    }
    finally {
      $this->lock->release($name);
    }
  }

}
