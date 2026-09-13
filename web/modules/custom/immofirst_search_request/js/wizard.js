/**
 * wizard.js
 * Vanilla JS only. Wrapped in Drupal.behaviors so everything re-attaches
 * correctly after each AJAX step transition (Drupal.attachBehaviors runs
 * automatically on the new DOM after ajax.js swaps in the response).
 *
 * Responsibilities (per spec):
 *   - Card selection visuals (Next-button enable/disable feedback;
 *     the actual selected look is pure CSS via :checked, see wizard.css)
 *   - Progress bar fill animation
 *   - Auto-scroll to the wizard heading ("Schritt X von 5") on step change
 *   - Character counter for textareas
 *   - Number input enhancements (no negative values, clean blur format)
 *   - Keyboard focus management on every step change (accessibility)
 *   - Copy-to-clipboard for the Step 5 success screen's reference number
 *
 * Deliberately does NOT duplicate Drupal's server-side validation —
 * disabling the Next button on Step 1 is a UX nicety only; the actual
 * "selection required" rule is still enforced by Drupal Form API
 * (#required) regardless of what this file does.
 */
(function (Drupal, once) {
  'use strict';

  // Prevents the browser's own default scroll-restoration (restoring
  // wherever the user last scrolled to on this URL, e.g. after a
  // reload or back/forward navigation) from fighting the explicit
  // scroll-to-top handled below for Step 1's own first paint. Setting
  // this once, at parse time, doesn't affect any other page on the
  // site — it's scoped to whatever page wizard.js is loaded on.
  if ('scrollRestoration' in window.history) {
    window.history.scrollRestoration = 'manual';
  }

  // Module-scoped (NOT per-element) record of the step we last saw,
  // read directly from '.wizard__step-content[data-wizard-step]'s own
  // attribute value — deliberately NOT tracked via once()'s node-
  // identity de-duplication (see the scroll behavior below for why).
  // Starts at null so the very first attach (whether that's the Step 0
  // overview or a direct/reloaded load of any step) is treated as
  // "just arrived", not a transition — see the ROOT CAUSE note in the
  // scroll behavior below for what that means specifically for a
  // fresh Step 1 load.
  var lastSeenStep = null;

  // How much breathing room to leave above the "Schritt X von 5"
  // label after scrolling (spec: ~24px).
  var SCROLL_OFFSET = 24;

  Drupal.behaviors.immofirstWizard = {
    attach: function (context) {

      /* ============================================
         Auto-scroll to the wizard heading ("Schritt X
         von 5") on every step change (Weiter, Zurück,
         and the final "Suchauftrag anlegen" step), but
         not on the very first full page load, and not
         on the Step 0 -> Step 1 transition.

         HISTORY:
         - v1 tracked "have I already scrolled for this
           node" via once()'s own node-identity
           de-duplication against '.wizard-progress' —
           only correct if Drupal's AJAX response always
           constructs a genuinely NEW DOM node for that
           element on every step change. Fragile: made
           this behave differently per step depending on
           incidental DOM-construction details rather than
           on the step change itself.
         - v2 (this file, below) instead tracks the STEP
           NUMBER's VALUE, from
           '.wizard__step-content[data-wizard-step]'
           (present on every step 1-5's markup — see
           buildForm() in SearchRequestWizardForm.php), in
           a module-scoped variable compared across
           attach() calls — correct regardless of DOM node
           identity. But this alone still wasn't enough
           for the 2 -> 3 transition specifically: see the
           ROOT CAUSE note just below.

         Scroll position: scrolls to '.wizard-progress'
         (the "Schritt X von 5" heading + progress bar)
         with ~24px of space kept above it, per spec,
         rather than flush against the very top of the
         viewport.
         ============================================ */
      var stepEl = document.querySelector('.wizard__step-content[data-wizard-step]');
      var currentStep = stepEl ? stepEl.getAttribute('data-wizard-step') : null;

      // ROOT CAUSE of Step 2 -> 3 specifically missing the scroll
      // (while 1->2, 3->4, and 4->5 all worked, even with the v2 fix
      // above already in place): this behavior's scroll-target
      // measurement (getBoundingClientRect()) ran SYNCHRONOUSLY, in
      // the same tick '#ajax' inserts the new DOM. Step 3 adds far
      // more markup than any other step (a full set of criteria-group
      // cards, each with its own checkbox grid) on top of the '#ajax'
      // submit button's own 'effect: fade' animation — between the
      // fade-in not having visually settled yet and the browser's own
      // scroll-anchoring compensating for such a large layout change
      // landing on top of it, '.wizard-progress' could still be at an
      // in-flux position at the exact moment this used to run — so
      // the computed target silently came out wrong only for this
      // one, unusually tall transition. Every other transition adds
      // little enough markup that this race was never wide enough to
      // actually miss.
      //
      // FIX: defer both the measurement AND the scroll itself by one
      // frame (requestAnimationFrame), so layout has already settled
      // by the time '.wizard-progress' is measured — this doesn't
      // special-case Step 3 (or any step); it just removes the race
      // for all of them equally. See also wizard.css's
      // 'overflow-anchor: none' on .wizard__step-content, which stops
      // the browser's own scroll-anchoring from fighting this for the
      // same reason.
      // ROOT CAUSE of Step 1 starting the page scrolled down near the
      // location field/"Weiter" button instead of at the top: this
      // behavior (correctly) never auto-scrolled on the very first
      // attach — otherwise the Step 0 overview page would jump around
      // on its own initial load too. But that same guard also skipped
      // a genuine FRESH load of Step 1 itself, which — unlike Step 0 —
      // does have a "Schritt X von 5" heading it should land on.
      // Combined with the browser's own default scroll-restoration
      // (restoring wherever the page was last scrolled to on this URL,
      // e.g. after a reload) — now turned off above — a fresh Step 1
      // load had nothing forcing it to the top, so it would show
      // wherever the browser/QA had last left it.
      //
      // FIX: treat a first-ever attach that's ALREADY on Step 1 as
      // "just arrived at Step 1", not as "no transition happened" —
      // scrolling it the same way any other transition does. A first
      // attach on Step 0, or a bookmarked/reloaded Step 2-5, is left
      // exactly as before (no auto-scroll).
      var isFreshStep1Load = (lastSeenStep === null && currentStep === '1');

      if (currentStep !== null && (isFreshStep1Load || (lastSeenStep !== null && lastSeenStep !== currentStep))) {
        requestAnimationFrame(function () {
          var progressEl = document.querySelector('.wizard-progress');
          if (progressEl) {
            var targetY = progressEl.getBoundingClientRect().top + window.pageYOffset - SCROLL_OFFSET;
            window.scrollTo({ top: Math.max(targetY, 0), behavior: 'smooth' });
          }
        });
      }
      lastSeenStep = currentStep;

      /* ============================================
         Step 1: disable "Weiter" until EVERY card
         group on the step has a selection.

         TEMP: button validation disabled for now — this
         whole block is commented out so "Weiter" is never
         JS-disabled, regardless of card selection. Re-enable
         by uncommenting it (together with the '#required'
         lines and validateForm() logic in
         SearchRequestWizardForm.php) once QA no longer needs
         to click through the wizard without selecting cards.

         (Kept for reference: Step 1 holds TWO independent
         card groups — Gesuchsart + Objektart, per the merged
         design — sharing the same "Weiter" button, so when
         re-enabled, gating must stay done once per step across
         all its card groups, not once per group; gating per
         group independently would let either group's own
         selection wrongly enable the shared button even while
         the other group is still unset.)
         ============================================
      once('immofirst-card-gate', '.wizard__step-content', context).forEach(function (stepEl) {
        var cardGroups = stepEl.querySelectorAll('.wizard-cards');
        if (!cardGroups.length) { return; }

        var form = stepEl.closest('form');
        if (!form) { return; }
        var nextBtn = form.querySelector('[data-wizard-next]');
        if (!nextBtn) { return; }

        var groupHasSelection = function (group) {
          return Array.prototype.some.call(
            group.querySelectorAll('input[type="radio"]'),
            function (r) { return r.checked; }
          );
        };

        var sync = function () {
          var allSelected = Array.prototype.every.call(cardGroups, groupHasSelection);
          nextBtn.disabled = !allSelected;
        };

        cardGroups.forEach(function (group) {
          group.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
          });
        });

        sync();
      });
      */

      /* ============================================
         Step 1: "Bitte wählen Sie eine Objektart aus."
         clears the instant a property-type card is
         picked, without waiting for another "Weiter"
         click. Presentation only — attachSiblingFieldError()
         in SearchRequestWizardForm.php (server-side
         '#required' validation) is what actually enforces
         the rule; this just removes the already-satisfied
         warning's markup from the DOM. Does not touch the
         location field's own validation/error handling.
         ============================================ */
      once('immofirst-clear-property-type-error', '.wizard-cards--objektart', context).forEach(function (fieldset) {
        fieldset.addEventListener('change', function (event) {
          var radio = event.target;
          if (!radio || radio.type !== 'radio' || !radio.checked) {
            return;
          }

          fieldset.classList.remove('error');
          fieldset.removeAttribute('aria-invalid');

          var errorEl = document.getElementById('property-type-error');
          if (errorEl) {
            errorEl.remove();
          }
        });
      });

      /* ============================================
         Progress bar: (re)trigger the width transition
         smoothly whenever the wizard re-renders.
         ============================================ */
      once('immofirst-progress', '.wizard-progress__fill', context).forEach(function (fill) {
        var targetWidth = fill.style.width;
        fill.style.width = '0%';
        // Force reflow so the browser registers the 0% state before
        // animating to the target width.
        void fill.offsetWidth;
        requestAnimationFrame(function () {
          fill.style.width = targetWidth;
        });
      });

      /* ============================================
         Character counter for textareas with
         data-char-counter="true" and a maxlength.
         ============================================ */
      once('immofirst-char-counter', '[data-char-counter]', context).forEach(function (textarea) {
        var max = parseInt(textarea.getAttribute('maxlength'), 10);
        if (!max) { return; }

        var counter = document.createElement('div');
        counter.className = 'wizard-char-counter';
        textarea.insertAdjacentElement('afterend', counter);

        var update = function () {
          var length = textarea.value.length;
          counter.textContent = length + ' / ' + max;
          counter.classList.toggle('wizard-char-counter--limit', length >= max);
        };

        textarea.addEventListener('input', update);
        update();
      });

      /* ============================================
         Number input enhancements: no negative values,
         no non-numeric leftover characters on blur.
         ============================================ */
      once('immofirst-number-input', '.wizard-input--number', context).forEach(function (input) {
        input.addEventListener('input', function () {
          if (input.value !== '' && Number(input.value) < 0) {
            input.value = '0';
          }
        });
        input.addEventListener('blur', function () {
          if (input.value === '') { return; }
          var num = Number(input.value);
          if (Number.isNaN(num)) {
            input.value = '';
          }
        });
      });

      /* ============================================
         Step 3: criteria card accordion.

         Every card, including the first one, now carries
         '.is-collapsible' and gets this same toggle — the
         first group's only difference is that it also
         starts with '.is-expanded' already in the markup
         (see buildStep3() in SearchRequestWizardForm.php),
         so it's the one group visibly open before the user
         clicks anything. Toggling only flips a presentation
         class ('is-expanded') on the card and updates
         aria-expanded on its header; it never touches the
         checkboxes inside, so selections, session
         persistence, and the "Weiter" AJAX step change are
         all unaffected.
         ============================================ */
      once('immofirst-criteria-accordion', '.wizard-criteria-card.is-collapsible', context).forEach(function (card) {
        var legend = card.querySelector('legend');
        if (!legend) { return; }

        legend.setAttribute('role', 'button');
        legend.setAttribute('tabindex', '0');
        legend.setAttribute('aria-expanded', card.classList.contains('is-expanded') ? 'true' : 'false');

        var toggle = function () {
          var expanded = card.classList.toggle('is-expanded');
          legend.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        };

        legend.addEventListener('click', toggle);
        legend.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
            event.preventDefault();
            toggle();
          }
        });
      });

      /* ============================================
         Step 5 success screen: copy the reference
         number ("SA-2026-000123") to the clipboard
         when its button is clicked, with a brief
         visual confirmation. Falls back to a hidden
         textarea + execCommand for browsers/contexts
         without the async Clipboard API (e.g. non-
         secure contexts); silently does nothing
         further if neither is available.
         ============================================ */
      once('immofirst-copy-reference', '[data-wizard-copy-button]', context).forEach(function (button) {
        var wrapper = button.closest('.wizard-success__reference');
        var valueEl = wrapper && wrapper.querySelector('[data-wizard-copy-value]');
        if (!valueEl) { return; }

        var showCopied = function () {
          button.classList.add('is-copied');
          window.setTimeout(function () {
            button.classList.remove('is-copied');
          }, 1500);
        };

        var fallbackCopy = function (text) {
          var textarea = document.createElement('textarea');
          textarea.value = text;
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          try {
            document.execCommand('copy');
            showCopied();
          }
          catch (error) {
            // Nothing more we can do — leave the UI as-is.
          }
          document.body.removeChild(textarea);
        };

        button.addEventListener('click', function () {
          var text = valueEl.getAttribute('data-wizard-copy-value') || valueEl.textContent.trim();

          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showCopied, function () {
              fallbackCopy(text);
            });
          }
          else {
            fallbackCopy(text);
          }
        });
      });

      /* ============================================
         Step 0 -> Step N: after "Jetzt kostenlos
         starten" opens Step 1, or after any
         "Weiter"/"Zurück" step change, move focus to
         the new step's first focusable field so
         keyboard/screen-reader users land on the new
         content. No validation logic here — purely a
         focus-management convenience. Runs for every
         step (data-wizard-step="1".."5"), not just the
         first one.
         ============================================ */
      once('immofirst-focus-step', '.wizard__step-content[data-wizard-step]', context).forEach(function (stepEl) {
        var focusable = stepEl.querySelector('input, textarea, select, button, [tabindex]');
        if (focusable) {
          focusable.focus({ preventScroll: true });
        }
      });

    }
  };

})(Drupal, once);