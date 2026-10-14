<?php

/**
 * @file
 * Post update functions for ImmoFirst Offers.
 */

declare(strict_types=1);

/**
 * Switches the Immobiliengesuch validity from months to days (default 90).
 *
 * Requests created from now on use search_request_term_days. Requests that
 * already exist keep the end date their e-mail 4.1 announced (+N months, the
 * unchanged search_request_term_months), so none of them ends earlier than
 * promised and nothing expires all at once.
 *
 * The switch time is stored in State, not config: it is specific to each
 * site (the moment this update runs there) and must not be overwritten by a
 * configuration import. Values that are already set are kept, so running
 * this again changes nothing.
 */
function immofirst_offers_post_update_term_in_days(): string {
  $config = \Drupal::configFactory()->getEditable('immofirst_offers.settings');
  $changed = [];
  if ($config->get('search_request_term_days') === NULL) {
    $config->set('search_request_term_days', 90);
    $changed[] = 'search_request_term_days = 90';
  }
  if ($changed) {
    $config->save();
  }
  $state = \Drupal::state();
  $key = \Drupal\immofirst_offers\NotificationMailer::TERM_DAYS_SINCE_STATE_KEY;
  if ($state->get($key) === NULL) {
    $since = \Drupal::time()->getRequestTime();
    $state->set($key, $since);
    $changed[] = 'switch time (state ' . $key . ') = ' . $since . ' (' . date('c', $since) . ')';
  }
  return $changed ? 'Immobiliengesuch validity in days: ' . implode(', ', $changed) . '.' : 'Immobiliengesuch validity in days: already configured, nothing changed.';
}

/**
 * Adds the missing langcode to immofirst_offers.settings.
 *
 * The object holds translatable (German) e-mail texts; without a langcode
 * Drupal's config validation rejects saving it through config forms.
 */
function immofirst_offers_post_update_settings_langcode(): string {
  $config = \Drupal::configFactory()->getEditable('immofirst_offers.settings');
  if ($config->get('langcode')) {
    return 'immofirst_offers.settings already has a langcode, nothing changed.';
  }
  $config->set('langcode', 'de')->save();
  return 'immofirst_offers.settings: langcode set to "de".';
}
