/**
 * @file
 * "Ort oder PLZ" autocomplete (homepage search + search-request wizard).
 *
 * Builds on Drupal's core autocomplete (jQuery UI: arrow keys, Enter,
 * Escape, mouse/touch, ARIA live region) for inputs marked with
 * [data-location-autocomplete], and replaces its data handling:
 *   - suggestions come from /standort/vorschlaege (German postcodes and
 *     places from the site's Mapbox provider, proxied by Drupal — the
 *     Mapbox token never reaches the browser),
 *   - no comma splitting (labels such as "Neustadt an der Aisch, Bayern"
 *     contain commas; core treats commas as a multi-value separator),
 *   - from 2 characters, 300 ms after the last keystroke, cached per term,
 *   - German status messages ("Keine passenden Orte gefunden", ...).
 *
 * On the homepage search the form is not sent while the field holds text
 * that was not picked from the suggestions. This is a convenience only:
 * the server verifies every location again (GermanLocationLookup).
 */
(function ($, Drupal, once) {
  'use strict';

  var MIN_LENGTH = 2;
  var cache = {};

  var TEXT = {
    none: 'Keine passenden Orte in Deutschland gefunden. Bitte prüfen Sie die Schreibweise oder geben Sie eine Postleitzahl ein.',
    unavailable: 'Die Ortssuche ist gerade nicht verfügbar. Bitte versuchen Sie es in einigen Minuten erneut.',
    select: 'Bitte wählen Sie einen Ort oder eine Postleitzahl aus der Vorschlagsliste aus.'
  };

  function normalize(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function setup(input) {
    var $input = $(input);
    var path = input.getAttribute('data-autocomplete-path');
    if (!path || !$input.data('ui-autocomplete')) {
      return;
    }

    // Values the user may submit: anything picked from (or offered by) the
    // suggestions, plus the value the page was rendered with.
    var accepted = {};
    // Set when a submit was blocked; kept until a suggestion is picked or
    // the text changes, so refreshing the list doesn't clear the error.
    var selectionRequired = false;
    if (input.value.trim() !== '') {
      accepted[normalize(input.value)] = true;
    }

    var hintId = (input.id || 'location') + '-location-hint';
    var hint = document.createElement('div');
    hint.id = hintId;
    hint.className = 'location-autocomplete__hint';
    hint.setAttribute('role', 'status');
    hint.setAttribute('aria-live', 'polite');
    hint.hidden = true;
    (input.closest('.search-card__field, .js-form-item') || input.parentNode).appendChild(hint);
    input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + hintId).trim());

    function showHint(text, isError) {
      hint.textContent = text || '';
      hint.hidden = !text;
      hint.classList.toggle('location-autocomplete__hint--error', !!isError);
      input.classList.toggle('location-autocomplete--invalid', !!isError);
      if (isError) {
        input.setAttribute('aria-invalid', 'true');
      }
      else {
        input.removeAttribute('aria-invalid');
      }
    }

    function handle(term, data, response) {
      var items = (data && data.items) || [];
      if (data && data.status === 'ok') {
        cache[term] = data;
        items.forEach(function (item) { accepted[normalize(item.value)] = true; });
        if (!items.length) {
          showHint(TEXT.none, selectionRequired);
        }
        else {
          showHint(selectionRequired ? TEXT.select : '', selectionRequired);
        }
      }
      else {
        showHint(TEXT.unavailable, false);
      }
      response(items);
    }

    $input.autocomplete('option', {
      minLength: MIN_LENGTH,
      delay: 300,
      search: function () {
        return input.value.trim().length >= MIN_LENGTH;
      },
      source: function (request, response) {
        var term = request.term.replace(/\s+/g, ' ').trim();
        if (cache[term]) {
          handle(term, cache[term], response);
          return;
        }
        input.classList.add('is-autocompleting');
        fetch(path + (path.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(term), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin'
        })
          .then(function (r) { return r.ok ? r.json() : { status: 'unavailable', items: [] }; })
          .catch(function () { return { status: 'unavailable', items: [] }; })
          .then(function (data) {
            input.classList.remove('is-autocompleting');
            handle(term, data, response);
          });
      },
      select: function (event, ui) {
        input.value = ui.item.value;
        accepted[normalize(ui.item.value)] = true;
        selectionRequired = false;
        showHint('', false);
        return false;
      },
      messages: {
        noResults: TEXT.none,
        results: function (amount) {
          return amount === 1
            ? '1 Vorschlag verfügbar. Mit den Pfeiltasten auswählen und mit der Eingabetaste übernehmen.'
            : amount + ' Vorschläge verfügbar. Mit den Pfeiltasten auswählen und mit der Eingabetaste übernehmen.';
        }
      }
    });
    $input.autocomplete('widget').addClass('location-autocomplete__menu');
    input.setAttribute('aria-autocomplete', 'list');

    input.addEventListener('input', function () {
      selectionRequired = false;
      if (input.value.trim().length < MIN_LENGTH) {
        showHint('', false);
      }
      else if (input.classList.contains('location-autocomplete--invalid')) {
        showHint('', false);
      }
    });

    // Homepage search only (the wizard is validated on the server and shows
    // its error inline): don't run a search for an unselected location.
    // Capture phase on document, so this runs before search-ajax.js's own
    // submit listener on the form.
    if (input.form && input.form.id === 'search-filter-form') {
      document.addEventListener('submit', function (event) {
        if (event.target !== input.form) {
          return;
        }
        var value = input.value.trim();
        if (value !== '' && !accepted[normalize(value)]) {
          event.preventDefault();
          event.stopPropagation();
          selectionRequired = true;
          showHint(TEXT.select, true);
          input.focus();
          $input.autocomplete('search', value);
        }
      }, true);
    }
  }

  Drupal.behaviors.immofirstLocationAutocomplete = {
    attach: function (context) {
      once('location-autocomplete', 'input[data-location-autocomplete]', context).forEach(setup);
    }
  };
})(jQuery, Drupal, once);
