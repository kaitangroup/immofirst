# Suchauftrag.de — Content Type, View & Demo Content

This package gives you everything the `suchauftrag_theme` needs on the
data side: the `Suchauftrag` content type, its 8 fields, the
`search_requests` View (block display `block_1`, matching the
`views_embed_view('search_requests', 'block_1')` call in
`suchauftrag_theme.theme`), and 6 demo nodes matching the reference design.

## What's in here

```
config/install/
  node.type.suchauftrag.yml                       Content type
  field.storage.node.field_request_type.yml        + field.field...  (Kaufen/Mieten)
  field.storage.node.field_request_date.yml         + field.field...  (Datum)
  field.storage.node.field_property_icon.yml        + field.field...  (Icon)
  field.storage.node.field_location.yml             + field.field...  (Standort)
  field.storage.node.field_area.yml                 + field.field...  (Fläche)
  field.storage.node.field_rooms.yml                + field.field...  (Zimmer)
  field.storage.node.field_price.yml                + field.field...  (Preis)
  field.storage.node.field_description.yml          + field.field...  (Beschreibung)
  views.view.search_requests.yml                    The View itself
scripts/
  create-demo-content.php                           6 example Suchaufträge
```

## Option A — Import via Drush (recommended, fastest)

From your Drupal root:

```bash
# 1. Copy the yml files into your site's config sync directory
cp config/install/*.yml web/sites/default/files/config_STAGING/ 2>/dev/null || true
# (Or wherever config_sync_directory points — check settings.php.
#  If unsure, use Option B below instead — no path guessing needed.)

# 2. Import
drush config:import --partial --source=web/sites/default/files/config_STAGING/

# 3. Create demo content
drush scr scripts/create-demo-content.php

# 4. Rebuild cache
drush cache:rebuild
```

`--partial` is important — it imports only these files without deleting
any other config that isn't in that folder.

## Option B — Import via Admin UI (no drush needed)

Go to **Configuration → Development → Configuration synchronization →
Single import** (`/admin/config/development/configuration/single/import`)
and import in this exact order (later files depend on earlier ones):

1. `node.type.suchauftrag.yml` → Configuration type: **Content type**
2. Each `field.storage.node.*.yml` → Configuration type: **Field storage settings**
3. Each `field.field.node.suchauftrag.*.yml` → Configuration type: **Field settings**
4. `views.view.search_requests.yml` → Configuration type: **View**

For each: paste the file's contents into the "Paste your configuration
here" box and click Import.

Then create content manually at `/node/add/suchauftrag`, or run the
demo script via `drush scr scripts/create-demo-content.php` once fields
exist (Drush still works even if you imported config via the UI).

## Verifying it worked

1. `/admin/structure/types` → you should see **Suchauftrag**.
2. `/admin/structure/views` → you should see **Suchaufträge** (id: `search_requests`)
   with a `block_1` display.
3. Visit the front page — the property-card grid should now show the
   6 demo listings styled exactly like the reference design, and the
   "124 Suchaufträge gefunden" count will be replaced by the real count.

## Notes

- The View filters on `status = 1` (published) and `type = suchauftrag`,
  sorted by `created` DESC, 6 items per page (matches the reference's
  "Weitere Suchaufträge laden" batch size). The **pager itself isn't
  wired to the "Load more" button yet** — that button is currently a
  static JS hook (`data-load-more` in `theme.js`) with no AJAX behavior.
  Say the word and I'll wire it to a Views AJAX "load more" (mini pager
  + fetch) so clicking it actually appends the next 6 results.
- `field_request_date` is formatted into "Heute" / "Gestern" / `d.m.Y`
  automatically by `suchauftrag_theme_preprocess_views_view_unformatted__search_requests()`
  — you don't need to do anything special when creating content, just
  pick the real date in the node edit form.
- If your real field names end up different from these (e.g. an
  existing site with its own schema), tell me the actual machine names
  and I'll update the `.theme` file's `$get()` calls to match instead
  of you renaming fields.
