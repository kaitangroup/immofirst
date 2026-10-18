/**
 * contact.js
 * Prevents accidental double submission of the contact form: after the
 * first submit the button is marked busy and further submits are ignored.
 * The button is not disabled, so its name/value are still posted.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.immofirstContactForm = {
    attach: function (context) {
      once('immofirstContactForm', 'form.immo-contact-form', context).forEach(function (form) {
        var submitted = false;
        form.addEventListener('submit', function (event) {
          if (submitted) {
            event.preventDefault();
            return;
          }
          submitted = true;
          var button = form.querySelector('.immo-contact-form__submit');
          if (button) {
            button.setAttribute('aria-disabled', 'true');
          }
        });
      });
    }
  };
})(Drupal, once);
