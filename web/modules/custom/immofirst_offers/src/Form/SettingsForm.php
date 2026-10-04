<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\immofirst_offers\OfferWorkflow;
use Drupal\webform\Entity\Webform;

/**
 * Texts and settings for the Tab.4 e-mails (immofirst_offers.settings).
 *
 * Plain config form — every value is exportable with drush config:export.
 */
final class SettingsForm extends ConfigFormBase {

  private const CONFIG = 'immofirst_offers.settings';

  private const EMAILS = [
    'search_request_created' => '4.1 Suchauftrag erfolgreich erstellt',
    'search_request_terminated' => '4.2 Suchauftrag beendet',
    'search_request_deleted' => '4.3 Suchauftrag gelöscht',
    'offer_sent' => '4.4 Angebot erfolgreich versendet (an Anbieter)',
    'offer_forward' => 'Angebot an Suchenden weiterleiten (Aktion „Senden“)',
  ];

  private const EMAIL_FIELDS = [
    'subject' => ['Betreff', 'textfield'],
    'heading' => ['Überschrift', 'textfield'],
    'intro' => ['Einleitung (eine Zeile pro Absatz)', 'textarea'],
    'summary_heading' => ['Überschrift Übersicht', 'textfield'],
    'next_heading' => ['Überschrift „Wie geht es weiter?“', 'textfield'],
    'next_text' => ['Text „Wie geht es weiter?“ (eine Zeile pro Absatz)', 'textarea'],
    'button_label' => ['Button-Beschriftung', 'textfield'],
    'button_caption' => ['Text am Button', 'textfield'],
    'extra_heading' => ['Überschrift Zusatzbox', 'textfield'],
    'extra_text' => ['Text Zusatzbox', 'textarea'],
  ];

  public function getFormId(): string {
    return 'immofirst_offers_settings';
  }

  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['search_request_term_months'] = [
      '#type' => 'number',
      '#title' => $this->t('Laufzeit eines Suchauftrags (Monate)'),
      '#description' => $this->t('Angezeigt in E-Mail 4.1; danach beendet der Cron den Suchauftrag und sendet E-Mail 4.2.'),
      '#min' => 1,
      '#required' => TRUE,
      '#config_target' => self::CONFIG . ':search_request_term_months',
    ];
    $form['provider_email_element'] = [
      '#type' => 'textfield',
      '#title' => $this->t('E-Mail-Element im Webform „@id“', ['@id' => OfferWorkflow::WEBFORM_ID]),
      '#description' => $this->t('Maschinenname des Elements mit der E-Mail-Adresse des Anbieters (Empfänger von 4.4).'),
      '#required' => TRUE,
      '#config_target' => self::CONFIG . ':provider_email_element',
    ];

    $form['site'] = ['#type' => 'details', '#title' => $this->t('Kopf- und Fußzeile'), '#open' => FALSE];
    foreach ([
      'brand_name' => 'Markenname',
      'tagline' => 'Slogan',
      'footer_note' => 'Hinweis in der Fußzeile',
      'footer_company' => 'Firmenzeile',
      'contact_url' => 'Link „Kontakt aufnehmen“ (Pfad oder URL, leer = ausblenden)',
      'imprint_url' => 'Link Impressum (Pfad oder URL, leer = ausblenden)',
      'privacy_url' => 'Link Datenschutz (Pfad oder URL, leer = ausblenden)',
    ] as $key => $label) {
      $form['site'][$key] = [
        '#type' => 'textfield',
        '#title' => $label,
        '#maxlength' => 512,
        '#config_target' => self::CONFIG . ':' . $key,
      ];
    }

    foreach (self::EMAILS as $email => $title) {
      $form[$email] = ['#type' => 'details', '#title' => $title, '#open' => FALSE];
      foreach (self::EMAIL_FIELDS as $field => [$label, $type]) {
        $form[$email][$email . '__' . $field] = [
          '#type' => $type,
          '#title' => $label,
          '#rows' => 3,
          '#maxlength' => 512,
          '#config_target' => self::CONFIG . ':emails.' . $email . '.' . $field,
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $element = trim((string) $form_state->getValue('provider_email_element'));
    $webform = Webform::load(OfferWorkflow::WEBFORM_ID);
    if ($webform && !$webform->getElementDecoded($element)) {
      $form_state->setErrorByName('provider_email_element', $this->t('Das Element „@el“ existiert nicht im Webform.', ['@el' => $element]));
    }
    parent::validateForm($form, $form_state);
  }

}
