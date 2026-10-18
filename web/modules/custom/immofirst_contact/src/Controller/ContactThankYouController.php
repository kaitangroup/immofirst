<?php

declare(strict_types=1);

namespace Drupal\immofirst_contact\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\immofirst_contact\Form\ContactForm;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the "Vielen Dank für Ihre Nachricht!" confirmation page.
 *
 * Shown once after a submission (ContactForm::SESSION_SENT); a direct visit
 * or reload is sent back to the contact form, so the page never confirms a
 * message that was not submitted.
 */
final class ContactThankYouController extends ControllerBase {

  public function build(Request $request): array|RedirectResponse {
    $session = $request->getSession();
    if (!$session->get(ContactForm::SESSION_SENT)) {
      return new RedirectResponse(Url::fromRoute('immofirst_contact.form')->toString());
    }
    $session->remove(ContactForm::SESSION_SENT);

    return [
      '#theme' => 'immofirst_contact_thank_you',
      '#attached' => [
        'library' => ['immofirst_contact/contact'],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
