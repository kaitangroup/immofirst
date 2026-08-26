/**
 * theme.js
 * Vanilla JS only — no jQuery.
 * Implements: mobile menu toggle, Kaufen/Mieten tabs, custom select,
 * bookmark toggle, sticky header shadow, smooth scroll for anchors.
 *
 * Wrapped in a Drupal behavior so it re-attaches correctly after AJAX
 * (e.g. Views AJAX pager / "load more").
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.suchauftragTheme = {
    attach: function (context) {

      /* ============================================
         Sticky header shadow after 20px scroll
         ============================================ */
      var header = context.querySelector ? context.querySelector('[data-header]') : null;
      if (header && !header.dataset.scrollBound) {
        header.dataset.scrollBound = 'true';
        var onScroll = function () {
          if (window.scrollY > 20) {
            header.classList.add('header--scrolled');
          } else {
            header.classList.remove('header--scrolled');
          }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
      }

      /* ============================================
         Mobile menu toggle
         ============================================ */
      var toggle = context.querySelector ? context.querySelector('[data-menu-toggle]') : null;
      if (toggle && !toggle.dataset.bound) {
        toggle.dataset.bound = 'true';
        var menu = document.getElementById(toggle.getAttribute('aria-controls'));

        toggle.addEventListener('click', function () {
          var isOpen = toggle.getAttribute('aria-expanded') === 'true';
          toggle.setAttribute('aria-expanded', String(!isOpen));
          if (menu) {
            menu.hidden = isOpen;
            menu.classList.toggle('is-open', !isOpen);
          }
          document.body.classList.toggle('u-no-scroll', !isOpen);
        });
      }

      /* ============================================
         Kaufen / Mieten tabs
         Real <input type="radio" name="art"> inside role="radiogroup":
         the browser + AT already expose selection state natively via
         :checked, and CSS handles the active look + sliding indicator
         (see components.css). No JS is required for this to function
         or to be accessible — intentionally not adding any handler here.
         ============================================ */

      /* ============================================
         Custom select: open/close + chevron rotate
         ============================================ */
      var selects = context.querySelectorAll ? context.querySelectorAll('[data-select]') : [];
      selects.forEach(function (select) {
        if (select.dataset.bound) { return; }
        select.dataset.bound = 'true';

        var trigger = select.querySelector('[aria-haspopup="listbox"]');
        var menu = select.querySelector('[data-select-menu]');
        var valueEl = select.querySelector('[data-select-value]');
        var input = select.querySelector('[data-select-input]');

        function closeSelect() {
          select.removeAttribute('data-open');
          trigger.setAttribute('aria-expanded', 'false');
          menu.hidden = true;
        }
        function openSelect() {
          select.setAttribute('data-open', '');
          trigger.setAttribute('aria-expanded', 'true');
          menu.hidden = false;
        }

        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          var isOpen = select.hasAttribute('data-open');
          // Close any other open selects first.
          document.querySelectorAll('[data-select][data-open]').forEach(function (openSel) {
            if (openSel !== select) {
              openSel.removeAttribute('data-open');
              var t = openSel.querySelector('[aria-haspopup="listbox"]');
              var m = openSel.querySelector('[data-select-menu]');
              if (t) t.setAttribute('aria-expanded', 'false');
              if (m) m.hidden = true;
            }
          });
          isOpen ? closeSelect() : openSelect();
        });

        menu.querySelectorAll('.select__option').forEach(function (option) {
          option.addEventListener('click', function () {
            menu.querySelectorAll('.select__option').forEach(function (o) {
              o.classList.remove('is-selected');
            });
            option.classList.add('is-selected');
            // Show the human-readable label (option's own text content),
            // but submit the machine value via the hidden input — these
            // differ e.g. for Immobilienart ('apartment' vs 'Wohnung').
            valueEl.textContent = option.textContent.trim();
            if (input) {
              input.value = option.getAttribute('data-value');
              input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            closeSelect();
            trigger.focus();
          });
        });

        document.addEventListener('click', function (e) {
          if (!select.contains(e.target)) { closeSelect(); }
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && select.hasAttribute('data-open')) {
            closeSelect();
            trigger.focus();
          }
        });
      });

      /* ============================================
         Bookmark toggle (property card)
         ============================================ */
      var bookmarks = context.querySelectorAll ? context.querySelectorAll('[data-bookmark]') : [];
      bookmarks.forEach(function (btn) {
        if (btn.dataset.bound) { return; }
        btn.dataset.bound = 'true';

        btn.addEventListener('click', function () {
          var active = btn.classList.toggle('is-active');
          btn.setAttribute('aria-pressed', String(active));
          btn.setAttribute('aria-label', active ? 'Von Favoriten entfernen' : 'Zu Favoriten hinzufügen');
        });
      });

      /* ============================================
         Reset filters link
         The link itself is a plain href="?" (strips all query params
         and reloads) — that alone fully resets every filter, since the
         View re-reads $_GET fresh on load. No JS needed for correctness;
         we don't intercept the click here on purpose.
         ============================================ */

      /* ============================================
         Smooth scroll for in-page anchors
         ============================================ */
      var anchors = context.querySelectorAll ? context.querySelectorAll('a[href^="#"]:not([href="#"])') : [];
      anchors.forEach(function (anchor) {
        if (anchor.dataset.bound) { return; }
        anchor.dataset.bound = 'true';

        anchor.addEventListener('click', function (e) {
          var targetId = anchor.getAttribute('href').slice(1);
          var target = document.getElementById(targetId);
          if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
    }
  };

})(Drupal);
