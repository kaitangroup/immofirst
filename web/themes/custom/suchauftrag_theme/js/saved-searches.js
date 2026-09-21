/**
 * saved-searches.js
 * Vanilla JS only — no jQuery.
 *
 * Bookmark PERSISTENCE (unchanged): bookmarking a property card still
 * stores ONLY that node's id in the visitor's own browser localStorage.
 * No Drupal field, View filter, or database table is created or
 * touched by this file — Drupal.suchauftragSavedSearches (defined
 * below) remains the single source of truth for the saved-id list,
 * still read by js/theme.js's bookmark-button click handler exactly as
 * before, so a card's bookmark icon and this file can never disagree
 * about what's saved.
 *
 * Bookmark FILTERING (changed): "Nur gemerkte anzeigen" used to hide/
 * show whatever cards happened to already be in the DOM — which could
 * never surface a bookmarked item outside the first loaded batch, and
 * "Weitere Suchaufträge laden" had no idea the filter was even active.
 * It now calls into js/search-ajax.js's Drupal.suchauftragSearchAjax
 * .refresh() — a REAL request to Drupal's own /views/ajax, through the
 * exact same pipeline the Kaufen/Mieten, Immobilienart, Ort/PLZ and
 * Sortieren controls already use — so the toggle now searches the
 * full result set on the server, not just what's currently rendered,
 * and "load more" (which also reads the bookmark state — see
 * search-ajax.js's bookmarkExtraParams()) continues to respect it.
 *
 * HOW BOOKMARKED IDS REACH THE SERVER: search-ajax.js's
 * bookmarkExtraParams() reads Drupal.suchauftragSavedSearches
 * .getAll() (this file) whenever isFilterActive() (also this file) is
 * true, and merges { bookmarked: [...ids] } into the same /views/ajax
 * POST request it already builds for every other filter. Those ids
 * are sent as repeated bookmarked[]=<id> parameters — matching
 * views.view.search_requests.yml's existing "bookmarked" exposed
 * filter (field: nid, plugin_id: in_operator, multiple: true) exactly
 * as Drupal's own exposed form would submit it, so no View
 * configuration change was needed: that filter already expected this
 * shape.
 *
 * Cross-file load order is safe regardless of which of the two
 * libraries' <script> tags executes first: both
 * Drupal.suchauftragSavedSearches (read by search-ajax.js) and
 * Drupal.suchauftragSearchAjax (read by this file, below) are only
 * ever accessed lazily — inside event handlers / attach() calls —
 * never at parse time.
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

  // Whether the "Nur gemerkte anzeigen" filter is currently switched
  // on. Deliberately NOT persisted across a page refresh — a full
  // reload always starts back at "show everything", matching how
  // every other filter/sort control on this page already behaves
  // (only the underlying saved-id data itself is expected to persist,
  // per the task spec).
  var filterActive = false;

  /**
   * Small shared API — used by THIS file's toggle button, by
   * js/theme.js's bookmark-button click handler (unchanged there),
   * and now also read by js/search-ajax.js's bookmarkExtraParams()
   * (see this file's header comment).
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
    /** Whether "Nur gemerkte anzeigen" is currently switched on — read by search-ajax.js so it knows whether to include the bookmark filter in a request. */
    isFilterActive: function () {
      return filterActive;
    }
  };

  /**
   * Renders the same visual "no results" state the View's own empty
   * text uses (.property-grid__empty, inside .property-grid — see
   * css/components.css and views-view--search-requests.html.twig)
   * directly, with no request to the server at all.
   *
   * Needed specifically for the "zero bookmarks" case: the View's
   * "bookmarked" filter is only ever a real, applied restriction when
   * at least one id is actually submitted — an exposed in_operator
   * filter with nothing in it is indistinguishable from "not applied"
   * to Views, which would show EVERY result instead of none. Rather
   * than sending a fake/sentinel id to force a zero-row match, this
   * is decided client-side (we already know the count is zero from
   * localStorage) and skips the network entirely.
   */
  function showEmptyBookmarksState() {
    var resultsRegion = document.getElementById('search-results-region');
    if (resultsRegion) {
      resultsRegion.innerHTML =
        '<div class="property-grid">' +
          '<div class="property-grid__empty">Sie haben noch keine Suchaufträge gemerkt.</div>' +
        '</div>';
    }

    var countEl = document.getElementById('search-requests-count');
    if (countEl) {
      countEl.textContent = '0 Suchaufträge gefunden';
    }

    var loadMoreWrapper = document.querySelector('.search-requests__load-more');
    if (loadMoreWrapper) {
      loadMoreWrapper.hidden = true;
    }
  }

  Drupal.behaviors.suchauftragSavedSearchesFilter = {
    attach: function (context) {
      // Re-apply the current filter to any cards newly present in
      // this attach call — covers the initial page load AND every
      // subsequent search-ajax.js update (new search results, "load
      // more" appends), so a visitor who has the filter switched on
      // doesn't see un-bookmarked cards sneak back in after either.
   //   applyFilterWithin(context);

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
       // applyFilterWithin(document);

        // Zero bookmarks: show the empty state directly, no request —
        // see showEmptyBookmarksState()'s docblock for why this can't
        // just be "send an empty bookmarked[] filter" instead.
        if (filterActive && readSavedIds().length === 0) {
          showEmptyBookmarksState();
          return;
        }

        // Every other case — turning the filter ON with at least one
        // bookmark, or turning it OFF again — goes through a real
        // server reload via search-ajax.js, which re-applies whatever
        // Kaufen/Mieten, Immobilienart, Ort/PLZ and Sortieren values
        // are already active (see search-ajax.js's own
        // Drupal.suchauftragSearchAjax.refresh() docblock) and merges
        // in the bookmark filter itself only when filterActive is now
        // true. This is what correctly restores the FULL result set
        // on "off" too, rather than just un-hiding whatever happens
        // to still be in the DOM (which, after a bookmarked-only
        // reload, is only ever the bookmarked subset — the rest was
        // never fetched to begin with).
        var searchAjax = window.Drupal && Drupal.suchauftragSearchAjax;
        if (searchAjax && searchAjax.refresh) {
          searchAjax.refresh();
        }
        else {
          console.warn('saved-searches: Drupal.suchauftragSearchAjax is not available — is suchauftrag_theme/search-ajax attached on this page?');
        }
      });
    }
  };

})(Drupal);
