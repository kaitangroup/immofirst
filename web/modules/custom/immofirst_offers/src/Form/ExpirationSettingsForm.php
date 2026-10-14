<?php

declare(strict_types=1);

namespace Drupal\immofirst_offers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\immofirst_offers\NotificationMailer;

/**
 * Validity period of an Immobiliengesuch, in days — and nothing else.
 *
 * Own route and permission ("administer immobiliengesuch expiration
 * settings"), so e.g. Plaing Admin can change it without access to the
 * e-mail texts (SettingsForm) or SMTP. Only the one config key is saved
 * (#config_target); the rest of immofirst_offers.settings is untouched.
 */
final class ExpirationSettingsForm extends ConfigFormBase {

  private const CONFIG = 'immofirst_offers.settings';

  public function getFormId(): string {
    return 'immofirst_offers_expiration_settings';
  }

  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['search_request_term_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Gültigkeitsdauer eines Immobiliengesuchs (Tage)'),
      '#description' => $this->t('Ganze Zahl von 1 bis @max. Nach Ablauf wird das Immobiliengesuch automatisch beendet (nicht mehr öffentlich sichtbar) und der Suchende erhält E-Mail 4.2. Gilt für Immobiliengesuche, die nach der Umstellung auf Tage erstellt wurden; eine Änderung wirkt auch auf bereits bestehende dieser Gesuche.', ['@max' => NotificationMailer::TERM_DAYS_MAX]),
      '#min' => 1,
      '#max' => NotificationMailer::TERM_DAYS_MAX,
      '#step' => 1,
      '#required' => TRUE,
      '#field_suffix' => $this->t('Tage'),
      '#config_target' => self::CONFIG . ':search_request_term_days',
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $value = $form_state->getValue('search_request_term_days');
    if (!is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < 1 || (int) $value > NotificationMailer::TERM_DAYS_MAX) {
      $form_state->setErrorByName('search_request_term_days', $this->t('Bitte geben Sie eine ganze Zahl von 1 bis @max ein.', ['@max' => NotificationMailer::TERM_DAYS_MAX]));
    }
    else {
      // Stored as an integer (config schema), not as the submitted string.
      $form_state->setValue('search_request_term_days', (int) $value);
    }
    parent::validateForm($form, $form_state);
  }

}
