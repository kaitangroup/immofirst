/**
 * @file
 * Homepage-specific behaviors: smooth-scroll CTAs and search-form validation.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.immobilienHomepage = {
    attach: function (context) {

      // Smooth-scroll for hash CTAs on the homepage.
      once('immobilien-scroll', 'a[href^="#"]', context).forEach(function (link) {
        link.addEventListener('click', function (e) {
          var target = document.querySelector(link.getAttribute('href'));
          if (!target) return;
          e.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });

      // Lightweight form validation for the search card.
      once('immobilien-form', '.immobilien-search-form', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          var region  = form.querySelector('#region');
          var budgetA = form.querySelector('#budget-min');
          var budgetB = form.querySelector('#budget-max');
          var ok = true;

          [region, budgetA, budgetB].forEach(function (field) {
            if (!field) return;
            field.classList.remove('is-error');
            if (field.selectedIndex === 0) { field.classList.add('is-error'); ok = false; }
          });

          if (!ok) {
            e.preventDefault();
            form.querySelector('.is-error').focus();
          }
        });
      });

    }
  };
})(Drupal, once);
