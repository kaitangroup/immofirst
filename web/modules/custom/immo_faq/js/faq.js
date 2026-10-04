/**
 * @file
 * FAQ accordion behavior.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.immoFaqAccordion = {
    attach: function (context) {
      once('immoFaqAccordion', '[data-faq-toggle]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var expanded = button.getAttribute('aria-expanded') === 'true';
          var targetId = button.getAttribute('aria-controls');
          var answer = document.getElementById(targetId);

          button.setAttribute('aria-expanded', String(!expanded));
          if (answer) {
            answer.hidden = expanded;
          }
        });
      });
    }
  };

})(Drupal, once);
