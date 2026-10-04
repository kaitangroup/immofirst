<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds and sends the Tab.4 e-mails (4.1–4.4) and the offer forward.
 *
 * Every e-mail goes through Drupal's Mail API (hook_mail() in
 * immofirst_offers.module → buildMessage() below). Texts come from the
 * immofirst_offers.settings config object; the layout is the
 * immofirst-offers-email.html.twig template.
 *
 * HTML delivery relies on system.mail's "immofirst_offers" interface entry
 * pointing at Webform's "webform_php_mail" plugin (core's php_mail strips
 * HTML). That entry lives in config/sync/system.mail.yml.
 */
final class NotificationMailer {

  use StringTranslationTrait;

  public const KEY_CREATED = 'search_request_created';
  public const KEY_TERMINATED = 'search_request_terminated';
  public const KEY_DELETED = 'search_request_deleted';
  public const KEY_OFFER_SENT = 'offer_sent';
  public const KEY_OFFER_FORWARD = 'offer_forward';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RendererInterface $renderer,
    private readonly EmailValidatorInterface $emailValidator,
    private readonly WebformTokenManagerInterface $webformTokenManager,
  ) {}

  /**
   * 4.1 — queued until the creating transaction has committed.
   */
  public function searchRequestCreated(NodeInterface $node): void {
    $this->afterCommit(fn () => $this->sendToRequester(self::KEY_CREATED, $node, ['node' => $node]));
  }

  /**
   * 4.2 — queued until the expiring save has committed.
   */
  public function searchRequestTerminated(NodeInterface $node): void {
    $this->afterCommit(fn () => $this->sendToRequester(self::KEY_TERMINATED, $node, ['node' => $node]));
  }

  /**
   * 4.3 — queued until the delete has committed.
   */
  public function searchRequestDeleted(NodeInterface $node, int $deletedAt): void {
    $this->afterCommit(fn () => $this->sendToRequester(self::KEY_DELETED, $node, ['node' => $node, 'deleted_at' => $deletedAt]));
  }

  /**
   * Forwards an approved offer to the search requester ("Senden").
   *
   * @return bool
   *   TRUE if the mail system accepted the message.
   */
  public function forwardOfferToRequester(WebformSubmissionInterface $submission, NodeInterface $node): bool {
    $providerEmail = $this->providerEmail($submission);
    return $this->sendToRequester(self::KEY_OFFER_FORWARD, $node, [
      'node' => $node,
      'webform_submission' => $submission,
    ], $providerEmail);
  }

  /**
   * 4.4 — confirmation to the provider that the offer was sent.
   */
  public function offerSent(WebformSubmissionInterface $submission, NodeInterface $node): bool {
    $to = $this->providerEmail($submission);
    if ($to === NULL) {
      $this->logger->error('4.4 not sent for submission @sid: no valid provider e-mail.', ['@sid' => $submission->id()]);
      return FALSE;
    }
    return $this->send(self::KEY_OFFER_SENT, $to, ['node' => $node, 'webform_submission' => $submission]);
  }

  /**
   * The search request's own e-mail address, if valid.
   */
  public function requesterEmail(NodeInterface $node): ?string {
    $email = $node->hasField('field_email') ? trim((string) $node->get('field_email')->value) : '';
    return $this->validEmail($email);
  }

  /**
   * The provider's e-mail from the configured Webform element, if valid.
   */
  public function providerEmail(WebformSubmissionInterface $submission): ?string {
    $element = (string) $this->settings()->get('provider_email_element');
    return $this->validEmail(trim((string) ($submission->getElementData($element) ?? '')));
  }

  /**
   * End of the search request's term (created + configured months).
   */
  public function termEnd(NodeInterface $node): int {
    $months = max(1, (int) $this->settings()->get('search_request_term_months'));
    return (new \DateTimeImmutable("@" . (int) $node->getCreatedTime()))->modify("+{$months} months")->getTimestamp();
  }

  /**
   * Fills in subject/body for hook_mail().
   */
  public function buildMessage(string $key, array &$message, array $params): void {
    $texts = $this->settings()->get('emails.' . $key) ?? [];
    /** @var \Drupal\node\NodeInterface $node */
    $node = $params['node'];
    $reference = (string) $node->get('field_reference_number')->value;

    $message['subject'] = trim(($texts['subject'] ?? '') . ($reference !== '' && $key !== self::KEY_OFFER_SENT ? ' (' . $reference . ')' : ''));
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
    // Tells webform_php_mail to keep the HTML instead of converting it to
    // plain text.
    $message['params']['html'] = TRUE;

    $build = [
      '#theme' => 'immofirst_offers_email',
      '#email_key' => $key,
      '#texts' => $texts,
      '#rows' => $this->summaryRows($key, $node, $params),
      '#button_url' => $this->buttonUrl($key, $node),
      '#offer' => isset($params['webform_submission']) && $key === self::KEY_OFFER_FORWARD
        ? $this->offerValuesMarkup($params['webform_submission'])
        : NULL,
      '#site' => $this->siteVariables(),
    ];
    $message['body'][] = (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Sends one message to the search request's e-mail address.
   */
  private function sendToRequester(string $key, NodeInterface $node, array $params, ?string $replyTo = NULL): bool {
    $to = $this->requesterEmail($node);
    if ($to === NULL) {
      $this->logger->error('E-mail @key not sent for search request @nid: no valid requester e-mail.', ['@key' => $key, '@nid' => $node->id()]);
      return FALSE;
    }
    return $this->send($key, $to, $params, $replyTo);
  }

  /**
   * Hands one message to Drupal's mail system and logs the outcome.
   */
  private function send(string $key, string $to, array $params, ?string $replyTo = NULL): bool {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('immofirst_offers', $key, $to, $langcode, $params, $replyTo);
    $context = ['@key' => $key, '@nid' => $params['node']->id()];
    if (empty($result['result'])) {
      $this->logger->error('E-mail @key for search request @nid could not be sent.', $context);
      return FALSE;
    }
    $this->logger->info('E-mail @key for search request @nid sent.', $context);
    return TRUE;
  }

  /**
   * Runs $callback once the current DB transaction has committed.
   *
   * Entity saves/deletes run inside a transaction, so lifecycle hooks fire
   * before the data is actually persisted. Deferring keeps 4.1–4.3 from
   * going out for a save that is later rolled back.
   */
  private function afterCommit(callable $callback): void {
    $transactions = $this->database->transactionManager();
    if (!$transactions->inTransaction()) {
      $callback();
      return;
    }
    $transactions->addPostTransactionCallback(static function (bool $committed) use ($callback): void {
      if ($committed) {
        $callback();
      }
    });
  }

  /**
   * "Auf einen Blick" rows per e-mail, as laid out in Tab.4.
   *
   * @return array<int, array{label: string, value: string, note?: string}>
   */
  private function summaryRows(string $key, NodeInterface $node, array $params): array {
    $created = (int) $node->getCreatedTime();
    $anzeige = ['label' => (string) $this->t('Anzeige'), 'value' => $this->adLabel($node)];
    $umkreis = ['label' => (string) $this->t('Umkreis'), 'value' => $this->radiusLabel($node)];
    $suchId = ['label' => (string) $this->t('Such-ID'), 'value' => (string) $node->get('field_reference_number')->value];
    $erstellt = ['label' => (string) $this->t('Erstellt am'), 'value' => $this->dateTime($created)];

    return match ($key) {
      self::KEY_CREATED => [$anzeige, $umkreis, $suchId, $erstellt, [
        'label' => (string) $this->t('Gültig für'),
        'value' => (string) $this->formatPlural((int) $this->settings()->get('search_request_term_months'), '1 Monat (bis @date)', '@count Monate (bis @date)', ['@date' => $this->date($this->termEnd($node))]),
        'note' => (string) $this->t('danach automatisch deaktiviert'),
      ]],
      self::KEY_TERMINATED => [$anzeige, $umkreis, $suchId, $erstellt, [
        'label' => (string) $this->t('Laufzeit'),
        'value' => $this->date($created) . ' – ' . $this->date($this->termEnd($node)),
      ]],
      self::KEY_DELETED => [$anzeige, $umkreis, $suchId, $erstellt, [
        'label' => (string) $this->t('Gelöscht am'),
        'value' => $this->dateTime((int) ($params['deleted_at'] ?? time())),
      ]],
      self::KEY_OFFER_SENT => [$anzeige, $umkreis, [
        'label' => (string) $this->t('Weitergeleitet an'),
        'value' => (string) $this->t('an die hinterlegte E-Mail-Adresse des Suchenden'),
      ], $suchId],
      default => [$anzeige, $umkreis, $suchId],
    };
  }

  /**
   * Button target per e-mail (Tab.4 annotations).
   */
  private function buttonUrl(string $key, NodeInterface $node): string {
    $url = match ($key) {
      // "Navigate directly to the search query using the corresponding
      // search ID."
      self::KEY_CREATED, self::KEY_OFFER_FORWARD => $node->toUrl(),
      // "create a new search request"
      self::KEY_TERMINATED, self::KEY_DELETED => Url::fromRoute('immofirst_search_request.wizard'),
      // "Navigate to all search queries."
      default => Url::fromRoute('<front>'),
    };
    return $url->setAbsolute()->toString();
  }

  /**
   * The provider's submitted offer, rendered by Webform from its elements.
   *
   * Uses Webform's own token so the e-mail always reflects the form as
   * configured in the Webform UI — no element names are hard-coded here.
   */
  private function offerValuesMarkup(WebformSubmissionInterface $submission): Markup {
    return Markup::create((string) $this->webformTokenManager->replace('[webform_submission:values:html]', $submission));
  }

  /**
   * "Miete – Haus gesucht".
   */
  private function adLabel(NodeInterface $node): string {
    $type = (string) $node->get('field_request_type')->value;
    $prefix = match ($type) {
      'mieten' => (string) $this->t('Miete'),
      'kaufen' => (string) $this->t('Kauf'),
      default => '',
    };
    return $prefix !== '' ? $prefix . ' – ' . $node->label() : (string) $node->label();
  }

  /**
   * "10 km rund um München".
   */
  private function radiusLabel(NodeInterface $node): string {
    $location = trim((string) $node->get('field_location')->value);
    $radius = $node->get('field_radius')->value;
    if ($radius === NULL || $radius === '') {
      return $location;
    }
    return (string) $this->t('@km km rund um @location', ['@km' => $radius, '@location' => $location]);
  }

  private function date(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'd.m.Y');
  }

  private function dateTime(int $timestamp): string {
    return (string) $this->t('@date um @time Uhr', [
      '@date' => $this->date($timestamp),
      '@time' => $this->dateFormatter->format($timestamp, 'custom', 'H:i'),
    ]);
  }

  /**
   * Header/footer values shared by every e-mail.
   */
  private function siteVariables(): array {
    $settings = $this->settings();
    $link = static function (string $value): string {
      if ($value === '') {
        return '';
      }
      return str_starts_with($value, '/')
        ? Url::fromUserInput($value)->setAbsolute()->toString()
        : $value;
    };
    return [
      'brand_name' => $settings->get('brand_name'),
      'tagline' => $settings->get('tagline'),
      'footer_note' => $settings->get('footer_note'),
      'footer_company' => $settings->get('footer_company'),
      'contact_url' => $link((string) $settings->get('contact_url')),
      'imprint_url' => $link((string) $settings->get('imprint_url')),
      'privacy_url' => $link((string) $settings->get('privacy_url')),
      'home_url' => Url::fromRoute('<front>')->setAbsolute()->toString(),
    ];
  }

  private function validEmail(string $email): ?string {
    return $email !== '' && $this->emailValidator->isValid($email) ? $email : NULL;
  }

  private function settings(): ImmutableConfig {
    return $this->configFactory->get('immofirst_offers.settings');
  }

}
