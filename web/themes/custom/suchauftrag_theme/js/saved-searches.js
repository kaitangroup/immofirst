/**
 * saved-searches.js
 * Vanilla JS only — no jQuery.
 *
 * Implements the "Nur gemerkte anzeigen" (saved searches) feature on
 * the homepage, entirely client-side:
 *   - Bookmarking a property card stores ONLY that node's id in the
 *     visitor's own browser localStorage. No Drupal field, View
 *     filter, or database table is created or touched by this file.
 *   - Clicking "Nur gemerkte anzeigen" filters the cards ALREADY
 *     loaded in the DOM down to bookmarked ones only; clicking again
 *     restores all of them. This never re-queries the server — it is
 *     a pure client-side show/hide over whatever
 *     views-view-unformatted--search-requests.html.twig has already
 *     rendered (including anything appended later by search-ajax.js's
 *     "load more" / filter-change flows).
 *
 * Drupal.suchauftragSavedSearches (defined below) is the single
 * source of truth for the saved-id list and is also read by
 * js/theme.js's bookmark-button click handler, so a card's bookmark
 * icon and this file's filtering can never disagree about what's
 * saved. Load order is guaranteed safe regardless of which file's
 * <script> tag executes first: Drupal.suchauftragSavedSearches is
 * only ever READ lazily, inside event handlers and attach() calls —
 * never at parse time — so by the time a visitor can actually click
 * anything, both files have already finished running.
 */
(function (Drupal) {
  'use strict';

  var STORAGE_KEY = 'suchauftrag:savedSearchNodeIds';

  /** Reads the saved id list from localStorage. Never throws — a disabled/unavailable localStorage (private browsing edge cases, quota errors) degrades to "nothing saved" rather than breaking the page. */
  function readSavedIds() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed.map(String) : [];
    }
    catch (e) {
      return [];
    }
  }

  /** Writes the saved id list back to localStorage. Silently no-ops on failure (see readSavedIds). */
  function writeSavedIds(ids) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    }
    catch (e) {
      // Nothing further to do — bookmarking simply won't persist this
      // session, but the click still updates the on-screen icon state
      // via the caller, so the UI doesn't look broken.
    }
  }

  /**
   * Small shared API — used by THIS file's filter button and by
   * js/theme.js's bookmark-button click handler (see that file's
   * updated "Bookmark toggle" section for how it's consumed).
   */
  Drupal.suchauftragSavedSearches = {
    isSaved: function (nodeId) {
      return readSavedIds().indexOf(String(nodeId)) !== -1;
    },
    /** Adds/removes nodeId from the saved list and persists it. Returns the NEW saved state (true = now saved). */
    toggle: function (nodeId) {
      nodeId = String(nodeId);
      var ids = readSavedIds();
      var index = ids.indexOf(nodeId);
      var nowSaved;
      if (index === -1) {
        ids.push(nodeId);
        nowSaved = true;
      }
      else {
        ids.splice(index, 1);
        nowSaved = false;
      }
      writeSavedIds(ids);
      return nowSaved;
    },
    getAll: readSavedIds,
  };

  // Whether the "Nur gemerkte anzeigen" filter is currently switched
  // on. Deliberately NOT persisted across a page refresh — a full
  // reload always starts back at "show everything", which matches
  // how every other filter/sort control on this page already behaves
  // (there is no other example on this page of a UI toggle state
  // surviving a refresh, only the underlying saved-id data itself is
  // expected to persist per the task spec).
  var filterActive = false;

  /** Shows/hides one card per the current filterActive state. A card with no data-node-id (shouldn't normally happen) is always shown, never hidden, so a data gap can't accidentally hide real content. */
  function applyFilterToCard(card) {
    var nodeId = card.getAttribute('data-node-id');
    var shouldHide = filterActive && nodeId && !Drupal.suchauftragSavedSearches.isSaved(nodeId);
    card.classList.toggle('u-hidden', !!shouldHide);
  }

  /** Applies the current filter state to every property card within a given root (document on first load; the AJAX-inserted region on subsequent search-ajax.js updates). */
  function applyFilterWithin(root) {
    var cards = root.querySelectorAll ? root.querySelectorAll('.property-card') : [];
    cards.forEach(function (card) {
      applyFilterToCard(card);
    });
  }

  Drupal.behaviors.suchauftragSavedSearchesFilter = {
    attach: function (context) {
      // Re-apply the current filter to any cards newly present in
      // this attach call — covers the initial page load AND every
      // subsequent search-ajax.js update (new search results, "load
      // more" appends), so a visitor who has the filter switched on
      // doesn't see un-bookmarked cards sneak back in after either.
      applyFilterWithin(context);

      var toggleBtn = context.querySelector ? context.querySelector('[data-saved-filter-toggle]') : null;
      if (!toggleBtn || toggleBtn.dataset.bound) { return; }
      toggleBtn.dataset.bound = 'true';

      toggleBtn.addEventListener('click', function () {
        filterActive = !filterActive;
        toggleBtn.classList.toggle('is-active', filterActive);
        toggleBtn.setAttribute('aria-pressed', String(filterActive));
        // Always re-scan the whole document, not just `context` — the
        // button lives outside #search-results-region, so a click on
        // it needs to reach every card currently on screen, wherever
        // they came from (initial render, AJAX search, or load more).
        applyFilterWithin(document);
      });
    }
  };

})(Drupal);
