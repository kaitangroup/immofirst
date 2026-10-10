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
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;
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
 * pointing at an HTML-capable SMTP plugin ("SMTPMailSystem", with
 * smtp.settings:smtp_allowhtml). Both live in config/sync.
 *
 * Roles: the "requester" created the search request (field_email); the
 * "provider" (Anbieter) submitted an offer via the offer_to_search_request
 * webform.
 */
final class NotificationMailer {

  use StringTranslationTrait;

  public const KEY_CREATED = 'search_request_created';
  public const KEY_TERMINATED = 'search_request_terminated';
  public const KEY_DELETED = 'search_request_deleted';
  public const KEY_OFFER_SENT = 'offer_sent';
  public const KEY_OFFER_FORWARD = 'offer_forward';

  /**
   * Element keys of the offer_to_search_request webform used by the forward.
   *
   * The provider's e-mail element is configurable
   * (immofirst_offers.settings:provider_email_element).
   */
  private const OFFER_ELEMENT_NAME = 'name';
  private const OFFER_ELEMENT_PHONE = 'telefon';
  private const OFFER_ELEMENT_MESSAGE = 'angebot';
  private const OFFER_ELEMENT_FILES = 'dateien';

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RendererInterface $renderer,
    private readonly EmailValidatorInterface $emailValidator,
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
      '#seller' => isset($params['webform_submission']) && $key === self::KEY_OFFER_FORWARD
        ? $this->offerDetails($params['webform_submission'])
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
   * @return array<int, array{label: string, value: string|\Drupal\Core\StringTranslation\TranslatableMarkup, note?: string}>
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
      self::KEY_OFFER_FORWARD => $this->requestDetailRows($node),
      default => [$anzeige, $umkreis, $suchId],
    };
  }

  /**
   * "Details zum Suchauftrag" for the offer forward; empty values are skipped.
   *
   * @return array<int, array{label: string, value: string}>
   */
  private function requestDetailRows(NodeInterface $node): array {
    $radius = $node->get('field_radius')->value;
    $rows = [
      [$this->t('Such-ID'), (string) $node->get('field_reference_number')->value],
      [$this->t('Immobilienart'), $this->listLabel($node, 'field_property_type')],
      [$this->t('Kauf / Miete'), match ((string) $node->get('field_request_type')->value) {
        'kaufen' => (string) $this->t('Kauf'),
        'mieten' => (string) $this->t('Miete'),
        default => '',
      }],
      [$this->t('Standort'), trim((string) $node->get('field_location')->value)],
      [$this->t('Umkreis'), $radius !== NULL && $radius !== '' ? $radius . ' km' : ''],
      [$this->t('Weitere Kriterien'), $this->criteriaSummary($node)],
    ];
    $out = [];
    foreach ($rows as [$label, $value]) {
      if ($value !== '') {
        $out[] = ['label' => (string) $label, 'value' => $value];
      }
    }
    return $out;
  }

  /**
   * "Wohnfläche ab 120 m² | 4 Zimmer | Kaufpreis bis 450.000 €".
   */
  private function criteriaSummary(NodeInterface $node): string {
    $parts = [];
    $add = function (string $min_field, string $max_field, string $label, string $unit, int $decimals = 0) use ($node, &$parts): void {
      if (!$node->hasField($min_field) || !$node->hasField($max_field)) {
        return;
      }
      $format = fn ($v) => number_format((float) $v, $decimals, ',', '.');
      $min = $node->get($min_field)->value;
      $max = $node->get($max_field)->value;
      $min = $min === NULL || $min === '' || (float) $min == 0 ? NULL : $format($min);
      $max = $max === NULL || $max === '' || (float) $max == 0 ? NULL : $format($max);
      $range = match (TRUE) {
        $min !== NULL && $max !== NULL => $min === $max ? $min : "$min – $max",
        $min !== NULL => $this->t('ab @value', ['@value' => $min]),
        $max !== NULL => $this->t('bis @value', ['@value' => $max]),
        default => NULL,
      };
      if ($range !== NULL) {
        $parts[] = trim(($label !== '' ? $label . ' ' : '') . $range . ' ' . $unit);
      }
    };
    $add('field_area_min', 'field_area_max', (string) $this->t('Wohnfläche'), 'm²');
    $add('field_rooms_min', 'field_rooms_max', '', (string) $this->t('Zimmer'));
    $add('field_land_size_min', 'field_land_size_max', (string) $this->t('Grundstück'), 'm²');
    $price_label = (string) $node->get('field_request_type')->value === 'kaufen' ? $this->t('Kaufpreis') : $this->t('Miete');
    $add('field_price_min', 'field_price_max', (string) $price_label, '€');
    $add('field_lease_price_min', 'field_lease_price_max', (string) $this->t('Pacht'), '€');
    return implode(' | ', $parts);
  }

  /**
   * German file size for the document list: "2,4 MB", "512 KB".
   */
  private function fileSize(int $bytes): string {
    return match (TRUE) {
      $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '.') . ' MB',
      $bytes >= 1024 => number_format($bytes / 1024, 0, ',', '.') . ' KB',
      default => $bytes . ' Bytes',
    };
  }

  /**
   * Label of a list field's stored value ("house" → "Haus").
   */
  private function listLabel(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $value = (string) $node->get($field)->value;
    $allowed = $node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?? [];
    return (string) ($allowed[$value] ?? $value);
  }

  /**
   * The provider's offer as plain values for the forward e-mail.
   *
   * Everything is returned as plain strings, so Twig autoescaping applies to
   * all of it. Uploaded files are listed by name and size only: they are
   * private webform files that only offer managers may download, so no
   * download link is put into the e-mail.
   *
   * @return array{name: string, phone: string, phone_href: string, email: string, message: string[], documents: array<int, array{name: string, size: string}>}
   */
  private function offerDetails(WebformSubmissionInterface $submission): array {
    $value = static fn (string $key): string => trim((string) (is_scalar($submission->getElementData($key)) ? $submission->getElementData($key) : ''));

    // As entered ("Ihr Name" is often a company); no salutation prefix.
    $name = $value(self::OFFER_ELEMENT_NAME);

    $phone = $value(self::OFFER_ELEMENT_PHONE);
    $message = str_replace(["\r\n", "\r"], "\n", $value(self::OFFER_ELEMENT_MESSAGE));

    $documents = [];
    $fids = array_filter((array) $submission->getElementData(self::OFFER_ELEMENT_FILES), 'is_numeric');
    if ($fids) {
      foreach (\Drupal::entityTypeManager()->getStorage('file')->loadMultiple($fids) as $file) {
        if ($file instanceof FileInterface) {
          $documents[] = ['name' => (string) $file->getFilename(), 'size' => $this->fileSize((int) $file->getSize())];
        }
      }
    }

    return [
      'name' => $name,
      'phone' => $phone,
      // Only digits and a leading "+" may end up in the tel: link.
      'phone_href' => $phone !== '' ? preg_replace('/(?!^\+)[^0-9]/', '', $phone) : '',
      'email' => $this->providerEmail($submission) ?? '',
      'message' => $message !== '' ? explode("\n", $message) : [],
      'documents' => $documents,
    ];
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
   *
   * The translated variant is returned as markup, not cast to a string:
   * t() has already escaped @location, so the template must not escape it
   * a second time ("&" would otherwise arrive as "&amp;amp;").
   */
  private function radiusLabel(NodeInterface $node): string|TranslatableMarkup {
    $location = trim((string) $node->get('field_location')->value);
    $radius = $node->get('field_radius')->value;
    if ($radius === NULL || $radius === '') {
      return $location;
    }
    return $this->t('@km km rund um @location', ['@km' => $radius, '@location' => $location]);
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
