/**
 * theme.js
 * Vanilla JS only — no jQuery.
 * Implements: mobile menu toggle, Kaufen/Mieten tabs, custom select,
 * bookmark toggle, sticky header shadow, smooth scroll for anchors.
 *
 * Wrapped in a Drupal behavior so it re-attaches correctly after AJAX
 * (e.g. Views AJAX pager / "load more").
 */
(function (Drupal, once) {
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

         Persisted via localStorage only (Drupal.suchauftragSavedSearches,
         defined in js/saved-searches.js, which loads first — see
         suchauftrag_theme.libraries.yml, "saved-searches" depends on
         "global" so this file is always available first when both are
         attached). No Drupal field or DB table is involved: only the
         node id (read from the enclosing .property-card's
         data-node-id, set in property-card.html.twig) is ever stored.

         Falls back to a plain, non-persisted visual toggle if that
         helper isn't present (e.g. a page that includes property
         cards but never attaches the saved-searches library) — same
         behavior as before this feature existed, so nothing regresses
         on such a page.

         Each property-card can render the bookmark button TWICE (a
         desktop copy and a mobile copy — see property-card.html.twig's
         header comment); both are updated together here via
         data-node-id so persisted state never looks out of sync
         between the two, even though only one is ever visible at a
         given viewport width.
         ============================================ */
      var savedSearches = window.Drupal && Drupal.suchauftragSavedSearches;

      function setBookmarkVisualState(btn, active) {
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', String(active));
        btn.setAttribute('aria-label', active ? 'Von Favoriten entfernen' : 'Zu Favoriten hinzufügen');
      }

      var bookmarks = context.querySelectorAll ? context.querySelectorAll('[data-bookmark]') : [];
      bookmarks.forEach(function (btn) {
        if (btn.dataset.bound) { return; }
        btn.dataset.bound = 'true';

        // The property-card grid supplies the node id via an
        // ANCESTOR .property-card's data-node-id (see above). The
        // search-request DETAIL page's own bookmark button (node--
        // search-request.html.twig) isn't inside a .property-card —
        // it puts data-node-id directly on the button itself instead.
        // Checking the button's own attribute first, falling back to
        // the ancestor lookup, lets both markups share this exact
        // same toggle/persistence code with nothing duplicated.
        var card = btn.closest('.property-card');
        var nodeId = btn.getAttribute('data-node-id') || (card ? card.getAttribute('data-node-id') : null);

        // Reflect already-saved state on initial paint/AJAX insert,
        // not just after a click — otherwise a bookmarked card would
        // always render un-bookmarked until clicked again.
        if (savedSearches && nodeId) {
          setBookmarkVisualState(btn, savedSearches.isSaved(nodeId));
        }

        btn.addEventListener('click', function () {
          var active;
          if (savedSearches && nodeId) {
            active = savedSearches.toggle(nodeId);
            // Keep the OTHER copy of this same card's bookmark button
            // (desktop/mobile duplicate) in sync too.
            if (card) {
              card.querySelectorAll('[data-bookmark]').forEach(function (copy) {
                setBookmarkVisualState(copy, active);
              });
            }
          }
          else {
            // No persistence available — same as this always behaved
            // before the saved-searches feature existed.
            active = btn.classList.toggle('is-active');
            btn.setAttribute('aria-pressed', String(active));
            btn.setAttribute('aria-label', active ? 'Von Favoriten entfernen' : 'Zu Favoriten hinzufügen');
          }
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
      once('suchauftragStickyFilter', '[data-search-filter]', context).forEach(function (section) {
        var sentinel = section.querySelector('[data-search-filter-sentinel]');
        var spacer = section.querySelector('[data-search-filter-spacer]');
        var bar = section.querySelector('[data-search-filter-bar]');
        var stickyBar = section.querySelector('[data-sticky-bar]');
        var toggleBtn = section.querySelector('[data-sticky-toggle]');
        var summaryEl = section.querySelector('[data-filter-summary]');
        var form = document.getElementById('search-filter-form');

        if (!sentinel || !bar) { return; }

        var mobileQuery = window.matchMedia('(max-width: 780px)');
        var naturalHeight = null;
        var sentinelY = null;

        function updateMeasurements() {
          var wasStuck = section.classList.contains('is-stuck');
          var wasCompact = section.classList.contains('is-compact');
          var wasExpanded = section.classList.contains('is-expanded');

          section.classList.remove('is-stuck', 'is-compact', 'is-expanded');
          if (spacer) { spacer.style.height = '0px'; }

          naturalHeight = section.getBoundingClientRect().height;
          sentinelY = sentinel.getBoundingClientRect().top + window.scrollY;

          if (wasStuck) {
            section.classList.add('is-stuck');
            if (wasCompact && !mobileQuery.matches) {
              section.classList.add('is-compact');
            }
            if (wasExpanded) {
              section.classList.add('is-expanded');
            }
            if (spacer && naturalHeight !== null) {
              spacer.style.height = naturalHeight + 'px';
            }
          }
        }

        function evaluateSticky() {
          if (sentinelY === null || naturalHeight === null) {
            updateMeasurements();
          }
          var stuck = window.scrollY >= sentinelY;
          section.classList.toggle('is-stuck', stuck);
          section.classList.toggle('is-compact', stuck && !mobileQuery.matches);

          if (stuck) {
            if (stickyBar) {
              section.style.setProperty('--search-filter-sticky-bar-height', stickyBar.getBoundingClientRect().height + 'px');
            }
            if (spacer && naturalHeight !== null) {
              spacer.style.height = naturalHeight + 'px';
            }
          } else {
            section.classList.remove('is-expanded');
            if (toggleBtn) { toggleBtn.setAttribute('aria-expanded', 'false'); }
            if (spacer) { spacer.style.height = '0px'; }
          }
        }

        // Remove old listeners before attaching new ones
        if (section._stickyScrollHandler) {
          window.removeEventListener('scroll', section._stickyScrollHandler);
        }
        if (section._stickyResizeHandler) {
          window.removeEventListener('resize', section._stickyResizeHandler);
        }
        if (section._stickyOrientationHandler) {
          window.removeEventListener('orientationchange', section._stickyOrientationHandler);
        }

        var onScroll = function () {
          evaluateSticky();
        };

        var onResize = function () {
          updateMeasurements();
          evaluateSticky();
        };

        var onOrientationChange = function () {
          updateMeasurements();
          evaluateSticky();
        };

        section._stickyScrollHandler = onScroll;
        section._stickyResizeHandler = onResize;
        section._stickyOrientationHandler = onOrientationChange;

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onResize, { passive: true });
        window.addEventListener('orientationchange', onOrientationChange, { passive: true });

        // Initialize after layout using requestAnimationFrame
        requestAnimationFrame(function () {
          updateMeasurements();
          evaluateSticky();
        });

        if (toggleBtn) {
          if (section._stickyToggleHandler) {
            toggleBtn.removeEventListener('click', section._stickyToggleHandler);
          }
          var onToggle = function () {
            var expanded = section.classList.toggle('is-expanded');
            toggleBtn.setAttribute('aria-expanded', String(expanded));
          };
          section._stickyToggleHandler = onToggle;
          toggleBtn.addEventListener('click', onToggle);
        }

        if (!window._suchauftragStickyDocBound) {
          window._suchauftragStickyDocBound = true;
          document.addEventListener('click', function (e) {
            document.querySelectorAll('[data-search-filter]').forEach(function (sec) {
              if (sec.classList.contains('is-expanded') && !sec.contains(e.target)) {
                sec.classList.remove('is-expanded');
                var tBtn = sec.querySelector('[data-sticky-toggle]');
                if (tBtn) { tBtn.setAttribute('aria-expanded', 'false'); }
              }
            });
          });
          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
              document.querySelectorAll('[data-search-filter]').forEach(function (sec) {
                if (sec.classList.contains('is-expanded')) {
                  sec.classList.remove('is-expanded');
                  var tBtn = sec.querySelector('[data-sticky-toggle]');
                  if (tBtn) {
                    tBtn.setAttribute('aria-expanded', 'false');
                    tBtn.focus();
                  }
                }
              });
            }
          });
        }

        /** Builds summary */
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
          form.addEventListener('submit', function () {
            section.classList.remove('is-expanded');
            if (toggleBtn) { toggleBtn.setAttribute('aria-expanded', 'false'); }
          });
          updateSummary();
        }
      });
    }
  };

})(Drupal, once);