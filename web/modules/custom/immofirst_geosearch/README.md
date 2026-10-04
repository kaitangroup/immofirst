# ImmoFirst Geosearch

Real distance ("Umkreis") search for the homepage Search Request list.

- **Storage:** `field_location_coordinates` (Geofield) on `search_request`. It is
  filled from `field_location` ("Ort oder PLZ") on save by **geocoder_field**,
  using the Geocoder provider `mapbox`. Geocoding only happens when the location
  changed or no coordinates exist. If a location can't be geocoded, the node
  still saves, the coordinates stay empty, and a warning goes to the `geocoder`
  log.
- **Search:** `hook_views_pre_build()` → `SearchRequestProximityFilter`. On
  `search_requests:block_1`, `ort` is geocoded once (cached) and Geofield's
  proximity filter (Haversine in SQL) is applied with `radius` km. The text
  match on `ort` is dropped in that case.
  - No `ort` means no radius filtering.
  - If `ort` can't be geocoded, the old text match on `ort` is used, without a
    radius, and a warning goes to the `immofirst_geosearch` log.
  - `radius` must be one of `immofirst_geosearch.settings:allowed_radii`;
    otherwise `default_radius` is used.
- **Backfill:** `drush immofirst-geosearch:geocode` (alias `igeo`) runs in
  batches. Nodes with valid coordinates are skipped. `--force` re-geocodes
  everything (and keeps existing coordinates on failure).

## Credentials

`config/sync/geocoder.geocoder_provider.mapbox.yml` is exported with an
**empty** `accessToken`. Each environment provides its own:

- `MAPBOX_ACCESS_TOKEN` environment variable, read in `settings.php`, or
- `$config['geocoder.geocoder_provider.mapbox']['configuration']['accessToken']`
  in that environment's (git-ignored) `settings.local.php`.

The exported country restriction is `de`. Local development overrides it to
`de,bd` in `settings.local.php` for the Bangladeshi test data. Don't do that on
staging or live. After changing the country, run `drush igeo --force`.

The status report (`/admin/reports/status`) shows a missing token and nodes
without coordinates.

## Deploying (staging / live)

1. Set `MAPBOX_ACCESS_TOKEN` for the web server and for CLI/Drush.
2. `drush updb -y && drush cim -y && drush cr` (or `drush deploy`). This
   enables the modules and creates the field.
3. `drush immofirst-geosearch:geocode` once. It is safe to re-run.
