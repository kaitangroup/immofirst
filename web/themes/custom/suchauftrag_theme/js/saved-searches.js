/**
 * saved-searches.js
 * Vanilla JS only — no jQuery.
 */
(function (Drupal) {
  'use strict';

  var STORAGE_KEY = 'suchauftrag:savedSearchNodeIds';

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

  function writeSavedIds(ids) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    }
    catch (e) {
    }
  }

  var filterActive = false;

  Drupal.suchauftragSavedSearches = {
    isSaved: function (nodeId) {
      return readSavedIds().indexOf(String(nodeId)) !== -1;
    },
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
      window.dispatchEvent(
        new CustomEvent('suchauftrag:bookmarksChanged', {
          detail: {
            ids: ids
          }
        })
      );
      return nowSaved;
    },
    getAll: readSavedIds,
    isFilterActive: function () {
      return filterActive;
    }
  };

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

  window.addEventListener('suchauftrag:bookmarksChanged', function () {
    if (filterActive) {
      if (readSavedIds().length === 0) {
        showEmptyBookmarksState();
      } else {
        var searchAjax = window.Drupal && Drupal.suchauftragSearchAjax;
        if (searchAjax && searchAjax.refresh) {
          searchAjax.refresh();
        }
      }
    }
  });

  Drupal.behaviors.suchauftragSavedSearchesFilter = {
    attach: function (context) {
      var toggleBtn = context.querySelector ? context.querySelector('[data-saved-filter-toggle]') : null;
      if (!toggleBtn || toggleBtn.dataset.bound) { return; }
      toggleBtn.dataset.bound = 'true';

      toggleBtn.addEventListener('click', function () {
        filterActive = !filterActive;
        toggleBtn.classList.toggle('is-active', filterActive);
        toggleBtn.setAttribute('aria-pressed', String(filterActive));

        if (filterActive && readSavedIds().length === 0) {
          showEmptyBookmarksState();
          return;
        }

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
