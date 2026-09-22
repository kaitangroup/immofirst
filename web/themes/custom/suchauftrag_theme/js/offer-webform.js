/**
 * @file
 * offer-webform.js
 *
 * Two independent, purely visual/UI enhancements for
 * webform--offer-to-search-request.html.twig. Neither touches form
 * submission, validation, or field values beyond what the browser's
 * own native <input type="file"> API already does.
 *
 *  1. Mobile bottom sheet: shows/hides the exact same form panel via
 *     CSS class toggles (no DOM move/clone) — open via the CTA
 *     button, close via the X button, Escape key, or backdrop click;
 *     optional drag-to-dismiss on the handle.
 *
 *  2. Dropzone enhancement: adds a clickable/droppable visual overlay
 *     (icon + action text + Drupal's own help text) on top of the
 *     already-functional native file input, and forwards
 *     drag-and-drop files onto that same input via a real `change`
 *     event, so Drupal/Webform's existing AJAX upload behaviors pick
 *     it up exactly as if the user had used the native file picker.
 *
 * Vanilla JS only — no external libraries.
 */
(function (Drupal) {
  'use strict';

  var OPEN_CLASS = 'is-open';
  var DRAGGING_CLASS = 'is-dragging';
  var SCROLL_LOCK_CLASS = 'offer-webform-scroll-lock';
  var DESKTOP_QUERY = '(min-width: 781px)';

  /**
   * Bottom sheet controller for a single [data-offer-webform] root.
   */
  function OfferSheet(root) {
    this.root = root;
    this.openBtn = root.querySelector('[data-offer-webform-open]');
    this.closeBtn = root.querySelector('[data-offer-webform-close]');
    this.backdrop = root.querySelector('[data-offer-webform-backdrop]');
    this.panel = root.querySelector('[data-offer-webform-panel]');
    this.handle = root.querySelector('[data-offer-webform-handle]');
    this.isOpen = false;
    this.lastFocused = null;
    this.dragStartY = null;
    this.dragCurrentY = null;
    this.dragging = false;

    if (!this.openBtn || !this.panel || !this.backdrop) {
      return;
    }

    this._onKeydown = this._onKeydown.bind(this);
    this._onDesktopChange = this._onDesktopChange.bind(this);

    this.openBtn.addEventListener('click', this.open.bind(this));
    if (this.closeBtn) {
      this.closeBtn.addEventListener('click', this.close.bind(this));
    }
    this.backdrop.addEventListener('click', this.close.bind(this));

    if (this.handle && window.PointerEvent) {
      this._bindDrag();
    }

    if (window.matchMedia) {
      this.desktopQuery = window.matchMedia(DESKTOP_QUERY);
      // Older Safari only supports addListener/removeListener.
      if (this.desktopQuery.addEventListener) {
        this.desktopQuery.addEventListener('change', this._onDesktopChange);
      }
      else if (this.desktopQuery.addListener) {
        this.desktopQuery.addListener(this._onDesktopChange);
      }
    }
  }

  OfferSheet.prototype._onDesktopChange = function (event) {
    // If the viewport grows past the mobile breakpoint while the
    // sheet happens to be open, don't leave it (and the scroll lock)
    // stuck on — the panel is about to render as the plain desktop
    // card instead.
    if (event.matches && this.isOpen) {
      this.close();
    }
  };

  OfferSheet.prototype.open = function () {
    if (this.isOpen) {
      return;
    }
    this.isOpen = true;
    this.lastFocused = document.activeElement;

    this.backdrop.hidden = false;
    // Force layout so the transition from the just-removed [hidden]
    // actually runs instead of jumping straight to the open state.
    // eslint-disable-next-line no-unused-expressions
    this.backdrop.offsetHeight;

    this.backdrop.classList.add(OPEN_CLASS);
    this.panel.classList.add(OPEN_CLASS);
    document.documentElement.classList.add(SCROLL_LOCK_CLASS);

    document.addEventListener('keydown', this._onKeydown);

    // Move focus into the dialog for keyboard/screen-reader users.
    window.setTimeout(function (panel) {
      panel.focus();
    }, 300, this.panel);
  };

  OfferSheet.prototype.close = function () {
    if (!this.isOpen) {
      return;
    }
    this.isOpen = false;

    this.backdrop.classList.remove(OPEN_CLASS);
    this.panel.classList.remove(OPEN_CLASS, DRAGGING_CLASS);
    this.panel.style.transform = '';
    document.documentElement.classList.remove(SCROLL_LOCK_CLASS);

    document.removeEventListener('keydown', this._onKeydown);

    var backdrop = this.backdrop;
    window.setTimeout(function () {
      backdrop.hidden = true;
    }, 250);

    if (this.lastFocused && typeof this.lastFocused.focus === 'function') {
      this.lastFocused.focus();
    }
  };

  OfferSheet.prototype._onKeydown = function (event) {
    if (event.key === 'Escape' || event.key === 'Esc') {
      event.preventDefault();
      this.close();
      return;
    }
    if (event.key === 'Tab') {
      this._trapFocus(event);
    }
  };

  /**
   * Minimal focus trap: keeps Tab/Shift+Tab cycling within the panel
   * while the sheet is open.
   */
  OfferSheet.prototype._trapFocus = function (event) {
    var focusable = this.panel.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) {
      return;
    }
    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    }
    else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  /**
   * Drag-to-dismiss on the handle, using Pointer Events (covers touch
   * + mouse + pen with one code path). Purely additive: X, Escape and
   * backdrop click already close the sheet on their own.
   */
  OfferSheet.prototype._bindDrag = function () {
    var self = this;
    var panelHeight = 0;

    this.handle.addEventListener('pointerdown', function (event) {
      if (!self.isOpen) {
        return;
      }
      self.dragging = true;
      self.dragStartY = event.clientY;
      panelHeight = self.panel.getBoundingClientRect().height || 1;
      self.panel.classList.add(DRAGGING_CLASS);
      self.handle.setPointerCapture(event.pointerId);
    });

    this.handle.addEventListener('pointermove', function (event) {
      if (!self.dragging) {
        return;
      }
      var delta = event.clientY - self.dragStartY;
      if (delta < 0) {
        delta = 0;
      }
      self.dragCurrentY = delta;
      self.panel.style.transform = 'translateY(' + delta + 'px)';
    });

    function endDrag(event) {
      if (!self.dragging) {
        return;
      }
      self.dragging = false;
      self.panel.classList.remove(DRAGGING_CLASS);

      var delta = self.dragCurrentY || 0;
      var threshold = panelHeight * 0.25;

      if (delta > threshold) {
        self.close();
      }
      else {
        self.panel.style.transform = '';
      }
      self.dragStartY = null;
      self.dragCurrentY = null;
    }

    this.handle.addEventListener('pointerup', endDrag);
    this.handle.addEventListener('pointercancel', endDrag);
  };

  /**
   * Wraps Webform's real submit <input> in a positioning wrapper and
   * layers a decorative icon+label overlay on top of it (design item
   * 2). The input itself is only reparented (insertBefore + 
   * appendChild move the existing node, they don't clone or replace
   * it), never removed or swapped for a new element, so any event
   * handling Drupal/Webform's own AJAX behaviors already attached to
   * it — however they attached it — keeps working exactly as before.
   * Idempotent via the dataset flag, same pattern as
   * enhanceDropzone() below.
   */
  function enhanceSubmit(input) {
    if (input.dataset.offerSubmitEnhanced) {
      return;
    }
    input.dataset.offerSubmitEnhanced = 'true';

    var wrap = document.createElement('span');
    wrap.className = 'offer-webform__submit-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    input.classList.add('offer-webform__submit-input');

    var visual = document.createElement('span');
    visual.className = 'offer-webform__submit-visual';
    visual.setAttribute('aria-hidden', 'true');

    var iconTemplate = document.querySelector('[data-offer-webform-submit-icon]');
    if (iconTemplate && 'content' in iconTemplate) {
      visual.appendChild(iconTemplate.content.cloneNode(true));
    }

    var label = document.createElement('span');
    // Mirrors the real input's own accessible label/value rather than
    // hardcoding a second copy of the string, so the two can never
    // drift out of sync with each other or with submit_button_label
    // in the webform's YAML.
    label.textContent = input.value || 'Angebot senden';
    visual.appendChild(label);

    wrap.appendChild(visual);
  }

  /**
   * Progressive dropzone enhancement: adds a decorative icon/action
   * overlay + moves Drupal's own help text into the grey helper slot,
   * and forwards HTML5 drag-and-drop onto the real file input.
   */
  function enhanceDropzone(container) {
    if (container.dataset.offerDropzoneEnhanced) {
      return;
    }
    var input = container.querySelector('input[type="file"]');
    if (!input) {
      return;
    }
    container.dataset.offerDropzoneEnhanced = 'true';

    var visual = document.createElement('div');
    visual.className = 'offer-webform__dropzone-visual';
    visual.setAttribute('aria-hidden', 'true');
    visual.innerHTML =
      '<svg class="offer-webform__dropzone-icon" width="32" height="32" viewBox="0 0 24 24" fill="none">' +
        '<path d="M12 16V4M12 4L7 9M12 4l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
        '<path d="M4 16v2.5A2.5 2.5 0 006.5 21h11a2.5 2.5 0 002.5-2.5V16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg>' +
      '<span class="offer-webform__dropzone-action">Dateien hier ablegen oder klicken</span>';

    container.insertBefore(visual, container.firstChild);

    // Re-use Drupal's own, already-accurate description text (real
    // configured extensions/size limit) as the grey helper line,
    // instead of hardcoding a copy here that could drift out of sync.
    var help = container.querySelector('.description, .file-upload-help, .form-item__description');
    if (help) {
      help.classList.add('offer-webform__dropzone-help');
      container.appendChild(help);
    }

    ['dragenter', 'dragover'].forEach(function (evtName) {
      container.addEventListener(evtName, function (event) {
        event.preventDefault();
        container.classList.add('is-dragover');
      });
    });

    container.addEventListener('dragleave', function (event) {
      if (event.target === container) {
        container.classList.remove('is-dragover');
      }
    });

    container.addEventListener('drop', function (event) {
      event.preventDefault();
      container.classList.remove('is-dragover');

      var files = event.dataTransfer && event.dataTransfer.files;
      if (!files || !files.length) {
        return;
      }
      try {
        input.files = files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      catch (err) {
        // Assigning FileList isn't supported in a few older browsers —
        // the native input is still fully clickable as a fallback, so
        // nothing further is needed here.
      }
    });
  }

  Drupal.behaviors.offerWebform = {
    attach: function (context) {
      var roots = context.querySelectorAll('[data-offer-webform]');

      roots.forEach(function (root) {
        if (!root.offerSheetInstance) {
          root.offerSheetInstance = new OfferSheet(root);
        }

        root.querySelectorAll('.form-managed-file, .js-form-managed-file').forEach(enhanceDropzone);
      });

      // Submit-button enhancement is looked up from `document`, not
      // scoped through `context`/`roots` above: Drupal's AJAX 'insert'
      // command can call attachBehaviors() with the newly-replaced
      // node ITSELF as context — if that happens to be the <form>
      // Webform replaces (a descendant of [data-offer-webform], not
      // an ancestor of it), `context.querySelectorAll('[data-offer-
      // webform]')` structurally can never find it (querySelectorAll
      // only ever matches descendants, and here the wrapper is an
      // ancestor), which would silently skip re-enhancing the submit
      // button on every validation-error AJAX rebuild. Searching from
      // `document` sidesteps that entirely; enhanceSubmit()'s own
      // dataset guard keeps this idempotent either way.
      document.querySelectorAll('.offer-webform form input[type="submit"], .offer-webform form button[type="submit"]').forEach(enhanceSubmit);
    }
  };
}(Drupal));