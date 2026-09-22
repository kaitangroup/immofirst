/**
 * search-ajax.js
 * Vanilla JS only — no jQuery, no Drupal.ajax().
 *
 * Connects the custom search form (components/search-form.html.twig)
 * and the "Weitere Suchaufträge laden" button (page--front.html.twig)
 * to the search_requests View (display: block_1) via Drupal's EXISTING
 * Views AJAX system (/views/ajax).
 */
(function (Drupal, drupalSettings) {
  'use strict';

  var VIEW_NAME = 'search_requests';
  var VIEW_DISPLAY_ID = 'block_1';
  var FILTER_IDENTIFIERS = ['art', 'immobilienart', 'ort', 'bookmarked'];
  var SORT_IDENTIFIER = 'sort';
  var DEFAULT_SORT = 'newest';

  function getAjaxViewSettings() {
    var ajaxViews = drupalSettings.views && drupalSettings.views.ajaxViews;
    if (!ajaxViews) {
      return null;
    }
    var matchKey = Object.keys(ajaxViews).filter(function (key) {
      return ajaxViews[key].view_name === VIEW_NAME && ajaxViews[key].view_display_id === VIEW_DISPLAY_ID;
    })[0];
    return matchKey ? ajaxViews[matchKey] : null;
  }

  function withoutEmpty(values) {
    var out = {};
    Object.keys(values || {}).forEach(function (key) {
      if (values[key] !== undefined && values[key] !== null && values[key] !== '') {
        out[key] = values[key];
      }
    });
    return out;
  }

  function onlyFilters(values) {
    var out = {};
    FILTER_IDENTIFIERS.forEach(function (key) {
      if (values && values[key]) {
        out[key] = values[key];
      }
    });
    return out;
  }

  function sanitizeDomId(domId) {
    return String(domId === undefined || domId === null ? '' : domId).replace(/[^a-zA-Z0-9_-]+/g, '-');
  }

  function findViewMarkup(commands, viewSettings) {
    commands = Array.isArray(commands) ? commands : [];
    window.__searchAjaxDebug = { commands: commands, viewSettings: viewSettings };

    var domId = viewSettings && viewSettings.view_dom_id ? sanitizeDomId(viewSettings.view_dom_id) : null;
    var domIdSelector = domId ? '.js-view-dom-id-' + domId : null;

    var htmlCommands = commands.filter(function (cmd) {
      return cmd && typeof cmd.data === 'string' && cmd.data.length > 0;
    });

    if (!htmlCommands.length) {
      return null;
    }

    var domIdMatches = domId ? htmlCommands.filter(function (cmd) {
      return typeof cmd.selector === 'string' && cmd.selector.indexOf(domId) !== -1;
    }) : [];

    var chosen =
      (domIdMatches.filter(function (cmd) { return cmd.method === 'replaceWith'; })[0] || domIdMatches[0])
      || htmlCommands.filter(function (cmd) {
        return cmd.data.indexOf('property-grid') !== -1;
      })[0]
      || htmlCommands.slice().sort(function (a, b) { return b.data.length - a.data.length; })[0];

    var fragment = new DOMParser().parseFromString(chosen.data, 'text/html').body;
    return (domIdSelector && fragment.querySelector(domIdSelector))
      || fragment.querySelector('.property-grid')
      || fragment.firstElementChild;
  }

  function readAppliedFiltersFromLocation() {
    var params = new URLSearchParams(location.search);
    var out = {};
    FILTER_IDENTIFIERS.forEach(function (key) {
      var value = params.getAll(key);
      if (value.length > 1) {
        out[key] = value;
      } else if (value.length === 1 && value[0]) {
        out[key] = value[0];
      }
    });
    return out;
  }

  function readAppliedSortFromLocation() {
    var value = new URLSearchParams(location.search).get(SORT_IDENTIFIER);
    return value === 'oldest' ? 'oldest' : DEFAULT_SORT;
  }

  function sortToViewParams(sortValue) {
    return {
      sort_by: 'created',
      sort_order: sortValue === 'oldest' ? 'ASC' : 'DESC'
    };
  }

  function readTotalFromGrid(root) {
    var footerEl = root ? root.querySelector('.property-grid__footer') : null;
    if (!footerEl) {
      return null;
    }
    var match = footerEl.textContent.match(/\d+/);
    return match ? parseInt(match[0], 10) : null;
  }

  function pagerHasNext(root) {
    var pagerEl = root ? root.querySelector('.property-grid__pager') : null;
    var hasNextLink = !!(pagerEl && pagerEl.querySelector('a[rel="next"], .pager__item--next a, a[title*="Nächste"], a[title*="next"]'));
    
    var total = readTotalFromGrid(root);
    var rows = root ? root.querySelector('.property-grid__rows') : null;
    var loadedCount = rows ? rows.querySelectorAll('.property-grid__item').length : 0;
    
    if (total !== null && loadedCount > 0) {
      return loadedCount < total;
    }
    return hasNextLink;
  }

  Drupal.behaviors.searchAjax = {
    attach: function () {
      var form = document.getElementById('search-filter-form');
      if (!form || form.dataset.searchAjaxBound) {
        return;
      }
      form.dataset.searchAjaxBound = 'true';

      var resultsRegion = document.getElementById('search-results-region');
      var countEl = document.getElementById('search-requests-count');
      var loadMoreWrapper = document.querySelector('.search-requests__load-more');
      var loadMoreBtn = loadMoreWrapper ? loadMoreWrapper.querySelector('[data-load-more]') : null;

      if (!resultsRegion) {
        return;
      }

      var currentFilters = {};
      var currentSort = DEFAULT_SORT;
      var loadMorePage = 1;

      function fetchViewCommands(filterValues, sortValue, extra) {
        var viewSettings = getAjaxViewSettings();
        if (!viewSettings) {
          return Promise.reject(new Error('No AJAX view settings found.'));
        }
        var ajaxPath = (drupalSettings.views && drupalSettings.views.ajax_path) || '/views/ajax';

        var params = new URLSearchParams();
        ['view_name', 'view_display_id', 'view_args', 'view_path', 'view_base_path', 'view_dom_id', 'pager_element']
          .forEach(function (key) {
            if (viewSettings[key] !== undefined) {
              params.set(key, viewSettings[key]);
            }
          });

        var bookmarkFilterActive = window.Drupal && Drupal.suchauftragSavedSearches && Drupal.suchauftragSavedSearches.isFilterActive();
        if (bookmarkFilterActive) {
          var bookmarkedIds = Drupal.suchauftragSavedSearches.getAll();
          bookmarkedIds.forEach(function (val) {
            params.append('bookmarked[]', val);
          });
        }

        var filters = onlyFilters(filterValues);
        Object.keys(filters).forEach(function (key) {
          if (key === 'bookmarked') {
            return;
          }
          if (Array.isArray(filters[key])) {
            filters[key].forEach(function (val) {
              params.append(key + '[]', val);
            });
          }
          else if (filters[key] !== undefined && filters[key] !== null && filters[key] !== '') {
            params.set(key, filters[key]);
          }
        });

        var sortParams = sortToViewParams(sortValue || DEFAULT_SORT);
        params.set('sort_by', sortParams.sort_by);
        params.set('sort_order', sortParams.sort_order);
        if (extra) {
          Object.keys(extra).forEach(function (key) {
            params.set(key, extra[key]);
          });
        }

        return fetch(ajaxPath + '?' + params.toString(), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: params.toString()
        }).then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP error ' + response.status);
          }
          return response.json();
        });
      }

      function existingDetailHrefs() {
        var hrefs = resultsRegion.querySelectorAll('.property-card__details');
        return new Set(Array.prototype.map.call(hrefs, function (a) {
          return a.getAttribute('href');
        }));
      }

      function setCount(n) {
        if (countEl) {
          countEl.textContent = n + ' Suchaufträge gefunden';
        }
      }

      function setLoadMoreVisible(visible) {
        if (loadMoreWrapper) {
          loadMoreWrapper.hidden = !visible;
        }
      }

      function readFormValues() {
        return Object.fromEntries(new FormData(form).entries());
      }

      function toQueryString(values) {
        var flat = {};
        Object.keys(values || {}).forEach(function (key) {
          if (Array.isArray(values[key])) {
            // query string representation for array
          } else if (values[key] !== undefined && values[key] !== null && values[key] !== '') {
            flat[key] = values[key];
          }
        });
        return new URLSearchParams(withoutEmpty(flat)).toString();
      }

      function currentUrlFor(values) {
        var qs = toQueryString(values);
        return location.pathname + (qs ? '?' + qs : '');
      }

      function fallbackToRealNavigation(values) {
        window.location.href = currentUrlFor(values);
      }

      function performSearch(values, options) {
        options = options || {};
        var bookmarkFilterActive = window.Drupal && Drupal.suchauftragSavedSearches && Drupal.suchauftragSavedSearches.isFilterActive();
        if (bookmarkFilterActive) {
          values = Object.assign({}, values, { bookmarked: Drupal.suchauftragSavedSearches.getAll() });
        } else {
          var copy = Object.assign({}, values);
          delete copy.bookmarked;
          values = copy;
        }
        currentFilters = onlyFilters(values);
        currentSort = (values && values.sort === 'oldest') ? 'oldest' : DEFAULT_SORT;
        loadMorePage = 1;
        resultsRegion.setAttribute('aria-busy', 'true');

        var viewSettingsForThisRequest = getAjaxViewSettings();

        return fetchViewCommands(currentFilters, currentSort, { page: '0' })
          .then(function (commands) {
            var grid = findViewMarkup(commands, viewSettingsForThisRequest);
            if (!grid) {
              fallbackToRealNavigation(values);
              return;
            }

            resultsRegion.innerHTML = '';
            resultsRegion.appendChild(grid);
            var rows = grid.querySelector('.property-grid__rows');
            var itemCount = rows ? rows.querySelectorAll('.property-grid__item').length : 0;
            var total = readTotalFromGrid(grid);
            setCount(total !== null ? total : itemCount);
            setLoadMoreVisible(itemCount > 0 && pagerHasNext(grid));

            Drupal.attachBehaviors(resultsRegion, drupalSettings);

            if (options.pushHistory !== false) {
              history.pushState({ params: withoutEmpty(values) }, '', currentUrlFor(values));
            }
          })
          .catch(function () {
            fallbackToRealNavigation(values);
          })
          .finally(function () {
            resultsRegion.removeAttribute('aria-busy');
          });
      }

      function loadMore() {
        if (!loadMoreBtn || loadMoreBtn.getAttribute('aria-busy') === 'true') {
          return;
        }
        var rowsContainer = resultsRegion.querySelector('.property-grid__rows');
        if (!rowsContainer) {
          return;
        }
        loadMoreBtn.setAttribute('aria-busy', 'true');
        var viewSettingsForThisRequest = getAjaxViewSettings();

        var loadMoreFilters = Object.assign({}, currentFilters);
        var bookmarkFilterActive = window.Drupal && Drupal.suchauftragSavedSearches && Drupal.suchauftragSavedSearches.isFilterActive();
        if (bookmarkFilterActive) {
          loadMoreFilters.bookmarked = Drupal.suchauftragSavedSearches.getAll();
        } else {
          delete loadMoreFilters.bookmarked;
        }

        fetchViewCommands(loadMoreFilters, currentSort, { page: String(loadMorePage) })
          .then(function (commands) {
            var grid = findViewMarkup(commands, viewSettingsForThisRequest);
            var newItems = grid ? grid.querySelectorAll('.property-grid__rows .property-grid__item') : [];

            if (!newItems.length) {
              setLoadMoreVisible(false);
              return;
            }

            var seen = existingDetailHrefs();
            var appended = 0;
            Array.prototype.forEach.call(newItems, function (item) {
              var link = item.querySelector('.property-card__details');
              var href = link ? link.getAttribute('href') : null;
              if (!href || seen.has(href)) {
                return;
              }
              seen.add(href);
              rowsContainer.appendChild(item);
              appended++;
            });

            if (appended > 0) {
              loadMorePage++;
              Drupal.attachBehaviors(rowsContainer, drupalSettings);
            }
            setLoadMoreVisible(pagerHasNext(grid));
          })
          .catch(function (error) {
            console.error('load more failed:', error);
          })
          .finally(function () {
            loadMoreBtn.removeAttribute('aria-busy');
          });
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(Object.assign({}, readFormValues(), { sort: currentSort }), { pushHistory: true });
      });

      var sortSelectTrigger = document.getElementById('sort-select');
      var sortSelectEl = sortSelectTrigger ? sortSelectTrigger.closest('[data-select]') : null;
      var sortSelectInput = sortSelectEl ? sortSelectEl.querySelector('[data-select-input]') : null;
      if (sortSelectInput) {
        sortSelectInput.addEventListener('change', function () {
          var values = Object.assign({}, currentFilters, { sort: sortSelectInput.value === 'oldest' ? 'oldest' : DEFAULT_SORT });
          performSearch(values, { pushHistory: true });
        });
      }

      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadMore);
      }

      window.addEventListener('popstate', function (event) {
        var values = (event.state && event.state.params) || Object.fromEntries(new URLSearchParams(location.search).entries());
        performSearch(values, { pushHistory: false });
      });

      var initialValues = Object.assign({}, withoutEmpty(readFormValues()), { sort: readAppliedSortFromLocation() });
      history.replaceState({ params: initialValues }, '', location.href);

      currentFilters = readAppliedFiltersFromLocation();
      currentSort = initialValues.sort;

      setLoadMoreVisible(pagerHasNext(resultsRegion));

      Drupal.suchauftragSearchAjax = {
        refresh: function () {
          performSearch(currentFilters, { pushHistory: false });
        }
      };

      var backToTopBtn = document.querySelector('[data-back-to-top]');
      if (backToTopBtn && !backToTopBtn.dataset.bound) {
        backToTopBtn.dataset.bound = 'true';
        window.addEventListener('scroll', function () {
          if (window.scrollY > 500) {
            backToTopBtn.removeAttribute('hidden');
          } else {
            backToTopBtn.setAttribute('hidden', 'true');
          }
        });
        backToTopBtn.addEventListener('click', function () {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      }
    }
  };

})(Drupal, drupalSettings);
