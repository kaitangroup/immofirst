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

  /**
   * Sticky / collapsible search filter.
   *
   * Desktop & tablet (>780px, see responsive.css): the full form pins
   * itself to the top of the viewport once scrolled past — no other
   * visual change.
   *
   * Mobile (<=780px): once pinned, the full form is replaced by a
   * compact "Filter ändern" bar; clicking it reveals the exact same
   * form (never a copy) as a panel underneath. See the markup/CSS
   * comments in templates/includes/search-filter.html.twig and the
   * "STICKY SEARCH FILTER" section of components.css/responsive.css.
   */
  Drupal.behaviors.suchauftragStickyFilter = {
    attach: function (context) {
      var section = context.querySelector ? context.querySelector('[data-search-filter]') : null;
      if (!section || section.dataset.stickyBound) { return; }
      section.dataset.stickyBound = 'true';

      var sentinel = section.querySelector('[data-search-filter-sentinel]');
      var spacer = section.querySelector('[data-search-filter-spacer]');
      var bar = section.querySelector('[data-search-filter-bar]');
      var stickyBar = section.querySelector('[data-sticky-bar]');
      var toggleBtn = section.querySelector('[data-sticky-toggle]');
      var summaryEl = section.querySelector('[data-filter-summary]');
      var form = document.getElementById('search-filter-form');

      if (!sentinel || !bar) { return; }

      var mobileQuery = window.matchMedia('(max-width: 780px)');

      function headerHeight() {
        var value = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height'));
        return isNaN(value) ? 72 : value;
      }

      /** Keeps the spacer the exact height of whichever element is currently pinned, so page content never jumps. */
      function syncSpacerHeight() {
        if (!spacer || !section.classList.contains('is-stuck')) { return; }
        var pinned = mobileQuery.matches ? stickyBar : bar;
        if (pinned) {
          spacer.style.height = pinned.getBoundingClientRect().height + 'px';
        }
      }

      /** Exposes the compact bar's own height as a CSS var, so the mobile "expanded panel" can position itself directly under it regardless of content changes. */
      function syncStickyBarHeightVar() {
        if (stickyBar) {
          section.style.setProperty('--search-filter-sticky-bar-height', stickyBar.getBoundingClientRect().height + 'px');
        }
      }

      function collapse() {
        section.classList.remove('is-expanded');
        if (toggleBtn) { toggleBtn.setAttribute('aria-expanded', 'false'); }
      }

      function setStuck(stuck) {
        section.classList.toggle('is-stuck', stuck);
        if (!stuck) {
          collapse();
        } else {
          syncStickyBarHeightVar();
        }
        syncSpacerHeight();
      }

      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            setStuck(!entry.isIntersecting);
          });
        }, { rootMargin: '-' + headerHeight() + 'px 0px 0px 0px', threshold: 0 });
        observer.observe(sentinel);
      }

      if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
          var expanded = section.classList.toggle('is-expanded');
          toggleBtn.setAttribute('aria-expanded', String(expanded));
        });
      }

      document.addEventListener('click', function (e) {
        if (section.classList.contains('is-expanded') && !section.contains(e.target)) {
          collapse();
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && section.classList.contains('is-expanded')) {
          collapse();
          if (toggleBtn) { toggleBtn.focus(); }
        }
      });

      /** Builds the "Mieten · Wohnung · München · 25 km"-style summary from the form's own current values — never a separate source of truth. */
      function updateSummary() {
        if (!summaryEl || !form) { return; }

        var parts = [];

        var checkedArt = form.querySelector('input[name="art"]:checked');
        if (checkedArt) {
          var artLabel = form.querySelector('label[for="' + checkedArt.id + '"]');
          if (artLabel) { parts.push(artLabel.textContent.trim()); }
        }

        form.querySelectorAll('[data-select]').forEach(function (select) {
          var valueEl = select.querySelector('[data-select-value]');
          var input = select.querySelector('[data-select-input]');
          if (valueEl && input && input.value) {
            parts.push(valueEl.textContent.trim());
          }
        });

        var ortInput = form.querySelector('input[name="ort"]');
        if (ortInput && ortInput.value.trim()) {
          parts.push(ortInput.value.trim());
        }

        summaryEl.textContent = parts.join(' · ');
      }

      if (form) {
        form.addEventListener('change', updateSummary);
        form.addEventListener('input', function (e) {
          if (e.target && e.target.name === 'ort') { updateSummary(); }
        });
        form.addEventListener('submit', collapse);
        updateSummary();
      }

      var resizeTimer = null;
      window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
          syncStickyBarHeightVar();
          syncSpacerHeight();
        }, 150);
      });
    }
  };

})(Drupal);