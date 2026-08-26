/**
 * @file
 * Global behaviors for the Immobilien theme.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.immobilienGlobal = {
    attach: function (context) {
      // Reveal-on-scroll animation for section cards.
      var reveals = once('immobilien-reveal', '.card, .step, .search-card, .hero, .why, .steps-section', context);
      if (!('IntersectionObserver' in window) || reveals.length === 0) return;

      reveals.forEach(function (el) { el.classList.add('is-hidden'); });

      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.remove('is-hidden');
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

      reveals.forEach(function (el) { io.observe(el); });
    }
  };
})(Drupal, once);
