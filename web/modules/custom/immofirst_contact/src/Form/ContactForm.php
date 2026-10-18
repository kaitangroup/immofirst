<?php

declare(strict_types=1);

namespace Drupal\immofirst_contact\Form;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contact form (Tab. 7 kontakt) at /kontakt.
 *
 * Sends the inquiry to RECIPIENT through Drupal's Mail API (the configured
 * SMTP mail system); the visitor's address is used as Reply-To only. On
 * success the visitor is redirected to the confirmation page; if the mail
 * system rejects the message the form is redisplayed with the entered
 * values and an error, never with a success message.
 *
 * Spam protection: an invisible honeypot field (validated server side) and
 * a per-IP flood limit. Submitted values are neither stored nor logged.
 */
final class ContactForm extends FormBase {

  /**
   * Inquiries go to this address; it is also shown on the page.
   */
  public const RECIPIENT = 'info@immo-gesuch.de';

  /**
   * Session flag that unlocks the confirmation page once.
   */
  public const SESSION_SENT = 'immofirst_contact.sent';

  /**
   * Subject options (Betreff).
   */
  public const SUBJECTS = [
    'allgemein' => 'Allgemeine Anfrage',
    'immobiliengesuch' => 'Fragen zum Immobiliengesuch',
    'technik' => 'Technisches Problem',
    'sonstiges' => 'Sonstiges',
  ];

  /**
   * Name of the honeypot field. Must stay empty; hidden from humans.
   */
  private const HONEYPOT = 'homepage';

  private const FLOOD_EVENT = 'immofirst_contact.submit';
  private const FLOOD_LIMIT = 5;
  private const FLOOD_WINDOW = 3600;

  private const NAME_MAX = 100;
  private const EMAIL_MAX = 254;
  private const MESSAGE_MAX = 5000;

  /**
   * Plain protected (not private/readonly) properties: when the form is
   * rebuilt after a mail failure it is cached and unserialized, and
   * DependencySerializationTrait (running in FormBase's scope) has to
   * re-inject these services.
   */
  public function __construct(
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected FloodInterface $flood,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('flood'),
    );
  }

  public function getFormId(): string {
    return 'immofirst_contact_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#theme'] = 'immofirst_contact_form';
    $form['#attributes']['class'][] = 'immo-contact-form';
    // Server-side validation only, like the wizard: errors are shown in
    // German directly below each field (see
    // template_preprocess_immofirst_contact_form()) instead of the
    // browser's own validation bubbles.
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#attached']['library'][] = 'immofirst_contact/contact';
    $form['#recipient'] = self::RECIPIENT;

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#placeholder' => $this->t('Vor- und Nachname'),
      '#required' => TRUE,
      '#required_error' => $this->t('Bitte geben Sie Ihren Namen ein.'),
      // Length is checked in validateForm() (German message); NULL keeps
      // core's English #maxlength check out.
      '#maxlength' => NULL,
      '#attributes' => ['autocomplete' => 'name', 'maxlength' => self::NAME_MAX],
      '#error_no_message' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('E-Mail'),
      '#placeholder' => 'name@beispiel.de',
      '#required' => TRUE,
      '#required_error' => $this->t('Bitte geben Sie Ihre E-Mail-Adresse ein.'),
      '#maxlength' => NULL,
      '#attributes' => ['autocomplete' => 'email', 'maxlength' => self::EMAIL_MAX],
      // Replaces core's (English) Email::validateEmail().
      '#element_validate' => [[self::class, 'validateEmail']],
      '#error_no_message' => TRUE,
    ];

    $form['subject'] = [
      '#type' => 'select',
      '#title' => $this->t('Betreff'),
      '#options' => self::SUBJECTS,
      // No preselection, so a subject is always chosen deliberately.
      '#empty_option' => $this->t('Bitte auswählen'),
      '#required' => TRUE,
      '#required_error' => $this->t('Bitte wählen Sie einen Betreff aus.'),
      // Required/allowed value are checked in validateForm() (German
      // message) instead of core's English "not allowed" error.
      '#validated' => TRUE,
      '#error_no_message' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ihre Nachricht'),
      '#placeholder' => $this->t('Wie können wir Ihnen helfen?'),
      '#required' => TRUE,
      '#required_error' => $this->t('Bitte geben Sie Ihre Nachricht ein.'),
      '#rows' => 5,
      '#resizable' => 'vertical',
      '#maxlength' => NULL,
      '#attributes' => ['maxlength' => self::MESSAGE_MAX],
      '#error_no_message' => TRUE,
    ];

    $privacyLink = Url::fromUserInput('/datenschutz')->toString();
    $form['privacy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ich habe die <a href=":url" target="_blank" rel="noopener">Datenschutzhinweise</a> gelesen.', [':url' => $privacyLink]),
      '#required' => TRUE,
      '#required_error' => $this->t('Bitte bestätigen Sie die Datenschutzhinweise.'),
      '#default_value' => 0,
      '#error_no_message' => TRUE,
    ];

    // Honeypot: off-screen (CSS), skipped by keyboard and autofill. Bots
    // that fill every field are caught in validateForm().
    $form[self::HONEYPOT] = [
      '#type' => 'textfield',
      '#title' => $this->t('Website'),
      '#default_value' => '',
      '#attributes' => [
        'autocomplete' => 'off',
        'tabindex' => '-1',
      ],
      '#wrapper_attributes' => [
        'class' => ['immo-contact-form__hp'],
        'aria-hidden' => 'true',
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Nachricht senden'),
      '#theme_wrappers' => ['immofirst_contact_submit'],
      '#attributes' => ['class' => ['btn', 'btn--primary', 'btn--block', 'immo-contact-form__submit']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->isSpam($form_state)) {
      // Not reported to the bot; submitForm() discards the submission.
      return;
    }

    // Required fields (#required_error) and the e-mail format
    // (validateEmail()) are checked before this runs.
    $name = (string) $form_state->getValue('name');
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
      $form_state->setErrorByName('name', $this->t('Bitte geben Sie einen gültigen Namen ein.'));
    }
    elseif (mb_strlen($name) > self::NAME_MAX) {
      $form_state->setErrorByName('name', $this->t('Ihr Name darf höchstens @max Zeichen lang sein.', ['@max' => self::NAME_MAX]));
    }
    if (!isset(self::SUBJECTS[(string) $form_state->getValue('subject')])) {
      $form_state->setErrorByName('subject', $this->t('Bitte wählen Sie einen Betreff aus.'));
    }
    if (mb_strlen((string) $form_state->getValue('message')) > self::MESSAGE_MAX) {
      $form_state->setErrorByName('message', $this->t('Ihre Nachricht darf höchstens @max Zeichen lang sein.', ['@max' => self::MESSAGE_MAX]));
    }

    if (!$form_state->getErrors() && !$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $form_state->setErrorByName('', $this->t('Sie haben in kurzer Zeit zu viele Nachrichten gesendet. Bitte versuchen Sie es später erneut.'));
    }
  }

  /**
   * Element validator for the e-mail field (German error message).
   */
  public static function validateEmail(array &$element, FormStateInterface $form_state): void {
    $value = trim((string) $element['#value']);
    $form_state->setValueForElement($element, $value);
    if ($value !== '' && (mb_strlen($value) > self::EMAIL_MAX || !\Drupal::service('email.validator')->isValid($value))) {
      $form_state->setError($element, t('Bitte geben Sie eine gültige E-Mail-Adresse ein.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->isSpam($form_state)) {
      $this->logger('immofirst_contact')->notice('Contact form submission discarded (honeypot).');
      $this->unlockThankYou($form_state);
      return;
    }

    $subjectKey = (string) $form_state->getValue('subject');
    $params = [
      'name' => trim((string) $form_state->getValue('name')),
      'email' => trim((string) $form_state->getValue('email')),
      'subject' => self::SUBJECTS[$subjectKey] ?? self::SUBJECTS['allgemein'],
      'message' => trim((string) $form_state->getValue('message')),
    ];

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('immofirst_contact', 'contact', self::RECIPIENT, $langcode, $params, $params['email']);

    if (empty($result['result'])) {
      $this->logger('immofirst_contact')->error('Contact form inquiry could not be sent: the mail system rejected the message.');
      // MailManager queues its own English "Unable to send email" error;
      // only the German message below is shown. Validation passed, so no
      // other errors are queued at this point.
      $this->messenger()->deleteByType(MessengerInterface::TYPE_ERROR);
      $this->messenger()->addError($this->t('Ihre Nachricht konnte leider nicht gesendet werden. Bitte versuchen Sie es später erneut.'));
      // Redisplay the form with the entered values.
      $form_state->setRebuild();
      return;
    }

    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);
    $this->unlockThankYou($form_state);
  }

  /**
   * Whether the honeypot field was filled in.
   */
  private function isSpam(FormStateInterface $form_state): bool {
    return trim((string) $form_state->getValue(self::HONEYPOT, '')) !== '';
  }

  /**
   * Allows one view of the confirmation page and redirects there.
   */
  private function unlockThankYou(FormStateInterface $form_state): void {
    $this->getRequest()->getSession()->set(self::SESSION_SENT, TRUE);
    $form_state->setRedirect('immofirst_contact.thank_you');
  }

}
