/**
 * search-ajax.js
 * Vanilla JS only — no jQuery, no Drupal.ajax().
 *
 * Connects the custom search form (components/search-form.html.twig)
 * and the "Weitere Suchaufträge laden" button (page--front.html.twig)
 * to the search_requests View (display: block_1) via Drupal's EXISTING
 * Views AJAX system (/views/ajax) — instead of a full-page GET reload,
 * and instead of any second/parallel filtering system.
 *
 * HOW THIS REUSES VIEWS AJAX:
 *   drupalSettings.views.ajaxViews (attached automatically because
 *   use_ajax: true is already set on the view) tells us view_name,
 *   view_display_id, view_dom_id, view_path, view_base_path and
 *   pager_element. We POST those plus our filter values to
 *   drupalSettings.views.ajax_path (never hardcoded) — Drupal core's
 *   own ViewAjaxController — which calls $view->setExposedInput() and
 *   renders exactly as a normal page load would. Nothing about
 *   filtering/querying/rendering is reimplemented; only the trigger
 *   (this form/button instead of Views' own exposed form/pager) and
 *   the transport (fetch() instead of jQuery-dependent Drupal.ajax())
 *   are custom.
 *
 * RESPONSE SHAPE — verified against core/modules/views/src/Controller/
 * ViewAjaxController.php (Drupal 11), not assumed:
 *   The controller always adds, in order:
 *     1. (optional) a "settings"/SetBrowserUrl-style command
 *     2. new ReplaceCommand(".js-view-dom-id-$dom_id", $preview)
 *     3. new PrependCommand(".js-view-dom-id-$dom_id", ['#type' => 'status_messages'])
 *   Both (2) and (3) serialize to the SAME JSON "command": "insert"
 *   shape (InsertCommand::render() — "method" is what differs:
 *   replaceWith vs prepend). That means picking "the first command
 *   with HTML-looking data" is NOT reliable — (3) is also an insert
 *   command, it's just normally empty/tiny (no messages to show).
 *   findViewMarkup() below matches (2) precisely, the same way
 *   Drupal's own Drupal.AjaxCommands.prototype.insert would: by the
 *   exact ".js-view-dom-id-{our dom id}" selector, sanitized the same
 *   way the controller sanitizes it server-side
 *   (preg_replace('/[^a-zA-Z0-9_-]+/', '-', $dom_id)). Two more
 *   fallback strategies exist below in case a core patch-level ever
 *   changes the selector format — see findViewMarkup().
 *
 *   Also: recent core reads view_name/view_display_id/view_path/etc.
 *   from $request->query first, falling back to $request->request
 *   (POST body); older core reads POST only. This file sends the
 *   same parameter set as BOTH the URL query string and the POST
 *   body, so it works regardless of which is checked.
 *
 * DIAGNOSTICS: every place this can fail to find something logs a
 * specific console.warn/error explaining what it looked for and what
 * it got instead (see findViewMarkup, fetchViewCommands) — and
 * window.__searchAjaxDebug always holds the last fetch's raw command
 * array, so "it's not updating" is diagnosable from the console
 * instead of being a silent no-op. If it ever DOES silently fail to
 * find the view's markup after all three fallback tiers, that is
 * treated exactly like a network failure (see performSearch): a real
 * navigation with the same filters, never a page that just sits
 * there unfiltered.
 *
 * ROOT CAUSE (fixed) — pager.type was "some":
 *   views.view.search_requests.yml's pager used to be type "some"
 *   ("Display a specified number of items"). Drupal\views\Plugin\
 *   views\pager\Some::query() sets LIMIT/OFFSET straight from the
 *   display's *config* (offset always 0) and never reads a page
 *   number from the request at all; useCountQuery() is also false,
 *   so no true result total ever existed server-side either. That
 *   meant every "load more" request — regardless of what page number
 *   this file sent — returned exactly the same first 12 rows, and
 *   the count badge had no real total to report.
 *
 *   Fixed in views.view.search_requests.yml by switching the pager to
 *   type "full" (a real SQL LIMIT/OFFSET pager that reads the current
 *   page from the request and runs a count query), and by adding a
 *   footer "Result summary" area (content: "@total") so the view's
 *   true total is present as plain text in every /views/ajax response
 *   — not just the initial page load. No JS change was required for
 *   the pager to start working: this file was already sending a page
 *   number on every "load more" request, exactly as noted below.
 *
 *   Two remaining bugs, fixed in this file:
 *     - "load more" seeded its filter state from the search FORM's
 *       current (default-checked) values instead of the filters that
 *       actually produced the page currently on screen. "Mieten" is
 *       checked in the UI by default even when nothing has been
 *       submitted, so clicking "load more" before ever searching sent
 *       an unintended art=mieten filter to a view that had rendered
 *       unfiltered — see readAppliedFiltersFromLocation() below, which
 *       now seeds from the URL exactly the way the server-side
 *       exposed-input logic does.
 *     - "has more results" was guessed from how many cards came back
 *       (and whether any were new after de-duplication) instead of
 *       asked from Drupal directly. With a real pager now in place,
 *       the rendered pager markup itself says whether there's a next
 *       page (a rel="next" link) — see pagerHasNext() below — which is
 *       what both the initial page load and every AJAX response use.
 *       The result count is likewise read from the view's own footer
 *       "Result summary" total (see readTotalFromGrid()) rather than
 *       from the number of cards currently rendered, so it no longer
 *       drifts as more cards are appended by "load more".
 */
(function (Drupal, drupalSettings) {
  'use strict';

  var VIEW_NAME = 'search_requests';
  var VIEW_DISPLAY_ID = 'block_1';

  // Real Views exposed filter identifiers only (views.view.search_
  // requests.yml). "radius" is deliberately excluded — it is UI-only
  // today (see search-form.html.twig's header comment), so it is
  // tracked in the URL/history/form state for a consistent search UI,
  // but never sent to the view as a filter.
  var FILTER_IDENTIFIERS = ['art', 'immobilienart', 'ort'];

  // This theme's own simplified sort vocabulary — the sort-select in
  // page--front.html.twig only ever offers these two. Translated to
  // the view's real exposed-sort identifiers (sort_by/sort_order) by
  // sortToViewParams() below, the same way suchauftrag_theme.theme
  // translates it server-side for the initial page load.
  var SORT_IDENTIFIER = 'sort';
  var DEFAULT_SORT = 'newest';

  /** Finds the ajaxViews settings entry Drupal generated for search_requests/block_1. */
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

  /** Drops empty/undefined values — an absent value must mean "no filter", never "filter for an empty string". */
  function withoutEmpty(values) {
    var out = {};
    Object.keys(values || {}).forEach(function (key) {
      if (values[key] !== undefined && values[key] !== null && values[key] !== '') {
        out[key] = values[key];
      }
    });
    return out;
  }

  /** Restricts a values object down to the real, filterable identifiers only (drops "radius" and anything else). */
  function onlyFilters(values) {
    var out = {};
    FILTER_IDENTIFIERS.forEach(function (key) {
      if (values && values[key]) {
        out[key] = values[key];
      }
    });
    return out;
  }

  /** Mirrors ViewAjaxController's own dom_id sanitization exactly, so our selector matches what the server actually used. */
  function sanitizeDomId(domId) {
    return String(domId === undefined || domId === null ? '' : domId).replace(/[^a-zA-Z0-9_-]+/g, '-');
  }

  /**
   * Finds the view's own rendered markup within a /views/ajax command
   * array and returns the matched element (never a whole document),
   * or null. Three tiers, most reliable first — see file header for
   * why a single "first HTML-looking command" check isn't safe:
   *
   *   1. The command whose selector is exactly this view's own
   *      ".js-view-dom-id-{id}" wrapper — what Drupal's own AJAX
   *      command processor would target. Most reliable: independent
   *      of this theme's class names entirely.
   *   2. Any command whose data contains this theme's own
   *      ".property-grid" wrapper class.
   *   3. The single largest HTML "insert" command by data length —
   *      status-message prepend commands are reliably tiny/empty, so
   *      the real view markup is reliably the biggest payload.
   *
   * Within whichever command is chosen, the actual grid element is
   * then located the same three ways (dom-id class, then
   * .property-grid, then just the first element in the markup).
   */
  function findViewMarkup(commands, viewSettings) {
    commands = Array.isArray(commands) ? commands : [];
    window.__searchAjaxDebug = { commands: commands, viewSettings: viewSettings };

    var domId = viewSettings && viewSettings.view_dom_id ? sanitizeDomId(viewSettings.view_dom_id) : null;
    var domIdSelector = domId ? '.js-view-dom-id-' + domId : null;

    var htmlCommands = commands.filter(function (cmd) {
      return cmd && typeof cmd.data === 'string' && cmd.data.length > 0;
    });

    if (!htmlCommands.length) {
      console.warn(
        'search-ajax: /views/ajax response had no command with HTML "data". Got command types:',
        commands.map(function (cmd) { return cmd && cmd.command; }),
        '— full response in window.__searchAjaxDebug.commands'
      );
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
    var grid = (domIdSelector && fragment.querySelector(domIdSelector))
      || fragment.querySelector('.property-grid')
      || fragment.firstElementChild;

    if (!grid) {
      console.warn(
        'search-ajax: matched an AJAX command but found no usable element inside it. Raw HTML (first 500 chars):',
        chosen.data.slice(0, 500)
      );
      return null;
    }

    return grid;
  }

  /**
   * The filters actually in effect for the page currently on screen —
   * read from the URL query string, the exact same way
   * suchauftrag_theme_preprocess_page__front() builds $exposed_input
   * server-side (only a present, non-empty value counts as a filter).
   * Deliberately NOT read from the form's current field values: the
   * form pre-selects "Mieten" by default even when no search has been
   * submitted, which used to leak into "load more" as an unintended
   * art=mieten filter on an otherwise-unfiltered page.
   */
  function readAppliedFiltersFromLocation() {
    var params = new URLSearchParams(location.search);
    var out = {};
    FILTER_IDENTIFIERS.forEach(function (key) {
      var value = params.get(key);
      if (value) {
        out[key] = value;
      }
    });
    return out;
  }

  /** Same idea as readAppliedFiltersFromLocation(), for the sort-select. Always resolves to a valid value — 'newest' if absent/unrecognized, matching the view's own default order. */
  function readAppliedSortFromLocation() {
    var value = new URLSearchParams(location.search).get(SORT_IDENTIFIER);
    return value === 'oldest' ? 'oldest' : DEFAULT_SORT;
  }

  /**
   * Translates this theme's own sort vocabulary (newest|oldest) into
   * the real exposed-sort query parameters Drupal reads — 'sort_by'
   * (which exposed sort; matches views.view.search_requests.yml's
   * sorts.created.expose.field_identifier) and 'sort_order' (ASC or
   * DESC). This is a fixed Views mechanism, not a custom identifier —
   * see Drupal\views\Plugin\views\exposed_form\ExposedFormPluginBase::query().
   */
  function sortToViewParams(sortValue) {
    return {
      sort_by: 'created',
      sort_order: sortValue === 'oldest' ? 'ASC' : 'DESC'
    };
  }

  /**
   * Whether a rendered .property-grid (from the initial page load or
   * an /views/ajax response) has a next page, per the view's own full
   * pager — never guessed from how many cards came back. Drupal's
   * core pager.html.twig marks the "next" link with rel="next"; no
   * next link in the markup means no next page, which is exactly how
   * the "hide Load More on the last page" requirement is satisfied.
   */
  function pagerHasNext(root) {
    var pagerEl = root ? root.querySelector('.property-grid__pager') : null;
    return !!(pagerEl && pagerEl.querySelector('a[rel="next"]'));
  }

  /**
   * The view's true total match count, read from its footer "Result
   * summary" area (views.view.search_requests.yml -> footer.result,
   * content: "@total") — plain digits, present in the initial page
   * load and in every /views/ajax response alike. Returns null if the
   * area isn't present (defensive — falls back to the caller's own
   * count in that case).
   */
  function readTotalFromGrid(root) {
    var footerEl = root ? root.querySelector('.property-grid__footer') : null;
    if (!footerEl) {
      return null;
    }
    var match = footerEl.textContent.match(/\d+/);
    return match ? parseInt(match[0], 10) : null;
  }

  Drupal.behaviors.searchAjax = {
    attach: function () {
      var form = document.getElementById('search-filter-form');

      // Guarded on the form itself (not on `context`, which varies —
      // see Drupal.attachBehaviors() calls below): this behavior must
      // wire up exactly once, however many times attachBehaviors runs.
      if (!form || form.dataset.searchAjaxBound) {
        return;
      }
      form.dataset.searchAjaxBound = 'true';

      var resultsRegion = document.getElementById('search-results-region');
      var countEl = document.getElementById('search-requests-count');
      var loadMoreWrapper = document.querySelector('.search-requests__load-more');
      var loadMoreBtn = loadMoreWrapper ? loadMoreWrapper.querySelector('[data-load-more]') : null;

      if (!resultsRegion) {
        console.warn('search-ajax: #search-results-region not found in the DOM — see the Twig changes in the accompanying notes.');
        return;
      }

      // The filters actually in effect for "load more" — set by every
      // performSearch() call (a fresh submit or a popstate restore),
      // so "load more" always continues the CURRENTLY active search,
      // not necessarily whatever is live in the form controls.
      var currentFilters = {};
      // Same idea, for the sort-select — so "load more" continues in
      // whatever sort order is currently applied, not always "newest".
      var currentSort = DEFAULT_SORT;
      var loadMorePage = 1;

      /**
       * POSTs to Drupal's own /views/ajax (path read from
       * drupalSettings, never hardcoded) with the view's identifying
       * settings plus the given filter values — sent as BOTH the URL
       * query string and the POST body (see file header: different
       * core patch levels check query-first-then-POST, or POST-only;
       * sending both works either way) — and returns the parsed JSON
       * AJAX-command array.
       */
      function fetchViewCommands(filterValues, sortValue, extra) {
        var viewSettings = getAjaxViewSettings();
        if (!viewSettings) {
          return Promise.reject(new Error(
            'search-ajax: no drupalSettings.views.ajaxViews entry for ' + VIEW_NAME + '/' + VIEW_DISPLAY_ID +
            ' — is use_ajax enabled on that display, and did this page actually render it?'
          ));
        }
        var ajaxPath = (drupalSettings.views && drupalSettings.views.ajax_path) || '/views/ajax';

        var params = new URLSearchParams();
        ['view_name', 'view_display_id', 'view_args', 'view_path', 'view_base_path', 'view_dom_id', 'pager_element']
          .forEach(function (key) {
            if (viewSettings[key] !== undefined) {
              params.set(key, viewSettings[key]);
            }
          });
        var filters = onlyFilters(filterValues);
        Object.keys(filters).forEach(function (key) {
          params.set(key, filters[key]);
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
            throw new Error('search-ajax: /views/ajax responded with HTTP ' + response.status);
          }
          return response.json();
        }).then(function (commands) {
          if (!Array.isArray(commands)) {
            console.warn('search-ajax: expected a JSON array of AJAX commands from /views/ajax, got:', commands);
          }
          return commands;
        });
      }

      /** The set of "Details ansehen" hrefs already on screen — each is unique per node, so this is what "load more" de-dupes against. */
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
        return new URLSearchParams(withoutEmpty(values)).toString();
      }

      function currentUrlFor(values) {
        var qs = toQueryString(values);
        return location.pathname + (qs ? '?' + qs : '');
      }

      function fallbackToRealNavigation(values, reason) {
        console.error('search-ajax: falling back to a full page reload —', reason);
        window.location.href = currentUrlFor(values);
      }

      /**
       * Runs a fresh search: fetches from /views/ajax, fully replaces
       * #search-results-region with the view's own rendered markup
       * (its rows, OR its own empty-message area — never a custom-
       * built one), resets "load more" back to page 0, and — unless
       * this call came from popstate — pushes the new URL. If the
       * view's markup genuinely can't be found in the response (see
       * findViewMarkup), that's treated the same as a network failure:
       * a real navigation, never a page that silently stays stale.
       */
      function performSearch(values, options) {
        options = options || {};
        currentFilters = onlyFilters(values);
        currentSort = (values && values.sort === 'oldest') ? 'oldest' : DEFAULT_SORT;
        loadMorePage = 1;
        resultsRegion.setAttribute('aria-busy', 'true');

        var viewSettingsForThisRequest = getAjaxViewSettings();

        return fetchViewCommands(currentFilters, currentSort, { page: '0' })
          .then(function (commands) {
            var grid = findViewMarkup(commands, viewSettingsForThisRequest);

            if (!grid) {
              fallbackToRealNavigation(values, 'no usable view markup in the /views/ajax response (see console warnings above)');
              return;
            }

            resultsRegion.innerHTML = '';
            resultsRegion.appendChild(grid);
            var rows = grid.querySelector('.property-grid__rows');
            var itemCount = rows ? rows.querySelectorAll('.property-grid__item').length : 0;
            var total = readTotalFromGrid(grid);
            setCount(total !== null ? total : itemCount);
            setLoadMoreVisible(itemCount > 0 && pagerHasNext(grid));

            // Re-run all Drupal behaviors (bookmark toggle, custom
            // selects, etc. from theme.js) scoped to the new markup —
            // theme.js's own dataset.bound guards make this safe to
            // call broadly without double-binding anything untouched.
            Drupal.attachBehaviors(resultsRegion, drupalSettings);

            if (options.pushHistory !== false) {
              history.pushState({ params: withoutEmpty(values) }, '', currentUrlFor(values));
            }
          })
          .catch(function (error) {
            // Network/server failure: fall back to a real navigation
            // with the same parameters rather than leaving a dead UI —
            // the equivalent server-side filtering already works
            // correctly on a full reload regardless of this script.
            fallbackToRealNavigation(values, error);
          })
          .finally(function () {
            resultsRegion.removeAttribute('aria-busy');
          });
      }

      /** Requests the next batch for the currently active filters and appends only genuinely new cards. */
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

        fetchViewCommands(currentFilters, currentSort, { page: String(loadMorePage) })
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
              // No stable href to key on — safer to skip than to risk
              // a duplicate we can't detect.
              if (!href || seen.has(href)) {
                return;
              }
              seen.add(href);
              rowsContainer.appendChild(item);
              appended++;
            });

            if (appended > 0) {
              loadMorePage++;
              // The count badge reports the view's true total match
              // count (see readTotalFromGrid), which does not change
              // just because more of it is now visible — it is
              // intentionally left untouched here.
              Drupal.attachBehaviors(rowsContainer, drupalSettings);
            }
            // The pager's own rel="next" link is the authoritative
            // "more results exist" signal — not a guess from how many
            // cards this request happened to return.
            setLoadMoreVisible(pagerHasNext(grid));
          })
          .catch(function (error) {
            console.error('search-ajax: load more failed —', error);
          })
          .finally(function () {
            loadMoreBtn.removeAttribute('aria-busy');
          });
      }

      /** Restores the form's visual state (tabs, custom selects, text input) to match a values object — used on popstate. */
      function applyFormState(values) {
        var art = values.art || 'mieten';
        var artRadio = form.querySelector('input[name="art"][value="' + CSS.escape(art) + '"]');
        if (artRadio) {
          artRadio.checked = true;
        }

        [
          { name: 'immobilienart', fallback: '' },
          { name: 'radius', fallback: '25' }
        ].forEach(function (field) {
          var input = form.querySelector('input[name="' + field.name + '"][data-select-input]');
          var select = input ? input.closest('[data-select]') : null;
          if (!input || !select) {
            return;
          }

          var value = values[field.name] || field.fallback;
          input.value = value;

          var valueEl = select.querySelector('[data-select-value]');
          var option = value ? select.querySelector('.select__option[data-value="' + CSS.escape(value) + '"]') : null;

          select.querySelectorAll('.select__option').forEach(function (o) {
            o.classList.remove('is-selected');
          });

          if (option) {
            option.classList.add('is-selected');
            if (valueEl) {
              valueEl.textContent = option.textContent.trim();
            }
          }
          else if (valueEl) {
            valueEl.textContent = valueEl.dataset.placeholder || valueEl.textContent;
          }
        });

        var ortInput = form.querySelector('input[name="ort"]');
        if (ortInput) {
          ortInput.value = values.ort || '';
        }

        // Sort-select lives outside #search-filter-form (see
        // page--front.html.twig), so it's synced separately here
        // rather than by the form-scoped loop above.
        var sortValue = values.sort === 'oldest' ? 'oldest' : DEFAULT_SORT;
        var sortSelectTrigger = document.getElementById('sort-select');
        var sortSelect = sortSelectTrigger ? sortSelectTrigger.closest('[data-select]') : null;
        var sortInput = sortSelect ? sortSelect.querySelector('[data-select-input]') : null;
        if (sortInput && sortSelect) {
          sortInput.value = sortValue;
          var sortValueEl = sortSelect.querySelector('[data-select-value]');
          var sortOption = sortSelect.querySelector('.select__option[data-value="' + CSS.escape(sortValue) + '"]');
          sortSelect.querySelectorAll('.select__option').forEach(function (o) {
            o.classList.remove('is-selected');
          });
          if (sortOption) {
            sortOption.classList.add('is-selected');
            if (sortValueEl) {
              sortValueEl.textContent = sortOption.textContent.trim();
            }
          }
        }
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(Object.assign({}, readFormValues(), { sort: currentSort }), { pushHistory: true });
      });

      // "Sortieren nach": lives outside #search-filter-form, so it's
      // wired separately. Its hidden input dispatches a bubbling
      // 'change' event on selection (see theme.js's [data-select]
      // behavior) — reuse the currently active filters and re-run the
      // search with the new sort order.
      var sortSelectTrigger = document.getElementById('sort-select');
      var sortSelectEl = sortSelectTrigger ? sortSelectTrigger.closest('[data-select]') : null;
      var sortSelectInput = sortSelectEl ? sortSelectEl.querySelector('[data-select-input]') : null;
      if (sortSelectInput) {
        sortSelectInput.addEventListener('change', function () {
          var values = Object.assign({}, readFormValues(), { sort: sortSelectInput.value === 'oldest' ? 'oldest' : DEFAULT_SORT });
          performSearch(values, { pushHistory: true });
        });
      }

      if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadMore);
      }

      window.addEventListener('popstate', function (event) {
        var values = (event.state && event.state.params) || Object.fromEntries(new URLSearchParams(location.search).entries());
        applyFormState(values);
        performSearch(values, { pushHistory: false });
      });

      // Normalize the initial history entry so the very first popstate
      // (e.g. the user's first action being "back") has a well-formed
      // state to restore.
      var initialValues = Object.assign({}, withoutEmpty(readFormValues()), { sort: readAppliedSortFromLocation() });
      history.replaceState({ params: initialValues }, '', location.href);

      // Seed currentFilters from the URL — the filters that actually
      // produced the page currently on screen — never from the form's
      // current field values (see the file header: the form's default
      // "Mieten" tab is checked even on an unfiltered page).
      currentFilters = readAppliedFiltersFromLocation();
      currentSort = initialValues.sort;

      // The initial batch is server-rendered already; just read its
      // own pager to decide whether "load more" has anything to do.
      setLoadMoreVisible(pagerHasNext(resultsRegion));
    }
  };

})(Drupal, drupalSettings);