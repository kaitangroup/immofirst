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
 *   - Auto-scroll to top of the wizard on step change
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

  Drupal.behaviors.immofirstWizard = {
    attach: function (context) {

      /* ============================================
         Auto-scroll to top of the wizard card on load
         (covers both the initial page load and every
         AJAX step transition, since attach() re-runs
         each time ajax.js swaps in new content).
         ============================================ */
      once('immofirst-scroll', '.wizard', context).forEach(function (wizardEl) {
        // Skip the very first attach on initial full page load so we
        // don't yank the scroll position on a fresh visit; only scroll
        // when this is a re-attach triggered by an AJAX step change.
        if (wizardEl.dataset.immofirstSeen) {
          wizardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        wizardEl.dataset.immofirstSeen = 'true';
      });

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

         Only cards carrying '.is-collapsible' (every
         group after the first, "Ausstattung der
         Wohnung") get a toggle at all — the first
         card's '.is-always-open' class is never
         selected here, so it has no click handler and
         can't be collapsed. Toggling only flips a
         presentation class ('is-expanded') on the card
         and updates aria-expanded on its header; it
         never touches the checkboxes inside, so
         selections, session persistence, and the
         "Weiter" AJAX step change are all unaffected.
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