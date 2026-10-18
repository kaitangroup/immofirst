# Deployment: Legal pages (Impressum, Datenschutz, AGB, Cookie-Hinweise, Widerrufsbelehrung)

## What is deployed, and how

| Part | Stored as | Transferred by |
|---|---|---|
| "Basic page" content type (`page`), its `body` field, form/view displays | Configuration | `drush cim` |
| `plaing_admin` role may create/edit/delete Basic pages | Configuration | `drush cim` |
| The five pages and their URL aliases | Content (nodes + path aliases) | `drush scr scripts/create-legal-pages.php` |

`drush cim` does **not** transfer page content. The script creates the pages on each
environment.

Configuration files added or changed in `config/sync/`:

- `node.type.page.yml`
- `field.field.node.page.body.yml` (reuses the existing `field.storage.node.body`)
- `core.entity_form_display.node.page.default.yml`
- `core.entity_view_display.node.page.default.yml`
- `core.entity_view_display.node.page.teaser.yml`
- `user.role.plaing_admin.yml` (+ `create page content`, `edit any page content`, `delete any page content`)

These are the definitions from Drupal core's `page_content_type` recipe. The body uses
the existing `basic_html` text format with CKEditor 5. No new module or dependency.

| Page | URL alias | Fixed node UUID |
|---|---|---|
| Impressum | `/impressum` | `d2bc047b-be27-490e-9665-624b507c5f83` |
| Datenschutz | `/datenschutz` | `d3420a8e-f7f9-4ace-8232-7c4800cb1431` |
| Allgemeine Geschäftsbedingungen (AGB) | `/agb` | `42f96675-7876-4a02-9906-62382bebcdf2` |
| Cookie-Hinweise | `/cookie-hinweise` | `d2ad2fc7-b917-4972-afbd-fefe9cc24dfa` |
| Widerrufsbelehrung | `/widerrufsbelehrung` | `a6107f77-30a7-4b58-8954-fe73e30e4f78` |

`/kontakt` is a route of the `immofirst_contact` module, not a node. The script does not touch it.

### How the script behaves (safe to run any number of times)

- It **only creates**. It never changes the title, body or published status of an existing page, so client edits are kept.
- It recognises its pages by their fixed UUID, so a re-run never creates duplicates.
- If a page exists but its alias is missing, only the alias is added back.
- If the alias already belongs to another node (e.g. a page created by hand), it reports `[SKIPPED]` and creates nothing for that page.
- If the alias belongs to a non-node path, or the page's alias points elsewhere, it reports `[CONFLICT]` and changes nothing.
- Options: `-- --dry-run` (report only), `-- --unpublished` (create missing pages as unpublished).

Every page starts with a visible "Platzhaltertext" notice. The text is placeholder only and
must be replaced with client-approved, legally reviewed content before it goes live.

## Commands

Run all commands from the project root (where `vendor/` is). Use the server's Drush
(`vendor/bin/drush` or `drush`).

### 1. Local (done once, before committing)

```bash
drush cst                                   # only the files listed above may differ
drush scr scripts/create-legal-pages.php    # create the pages locally
drush cr
```

Then commit `config/sync/*` (the six files above), `scripts/create-legal-pages.php` and this document.

> Do **not** run a full `drush cex -y` just for this change if other local config is
> unfinished (at the time of writing, `core.extension` also differs because the
> `immofirst_contact` module is enabled locally but not yet exported). Export that
> separately when that work is committed.

### 2. Staging

```bash
# 0. Back up the database
drush sql:dump --gzip --result-file=../backups/before-legal-pages-$(date +%F).sql

# 1. Deploy the code as usual (e.g. git pull of the release branch)

# 2. Check what the import will change
drush cst
#    Expected for this change: the 5 page files "Only in sync dir" and
#    user.role.plaing_admin "Different". STOP if node.type.page shows
#    "Different" (a page type already exists there — importing would
#    replace it), or if anything unexpected would be changed/deleted.

# 3. Database updates, config import, cache
drush updb -y
drush cim -y
drush cr

# 4. Content: preview, then create the pages
drush scr scripts/create-legal-pages.php -- --dry-run
drush scr scripts/create-legal-pages.php
drush cr
```

Check on staging:

- `/impressum`, `/datenschutz`, `/agb`, `/cookie-hinweise` and `/widerrufsbelehrung` load (HTTP 200); `/kontakt` is unchanged.
- Log in as a `plaing_admin` user, open a page under *Inhalt*, edit it and save, and toggle *Veröffentlicht*.
- Re-run `drush scr scripts/create-legal-pages.php`. Every line should read `[OK] … unchanged`.

### 3. Live (after staging is approved)

Same sequence as staging, including the backup, `drush cst` check and dry run:

```bash
drush sql:dump --gzip --result-file=../backups/before-legal-pages-$(date +%F).sql
# deploy code
drush cst
drush updb -y
drush cim -y
drush cr
drush scr scripts/create-legal-pages.php -- --dry-run
drush scr scripts/create-legal-pages.php                  # or: -- --unpublished
drush cr
```

**Decide before running on live:** by default the pages are created *published*, so the
existing footer links (Impressum, Datenschutz, AGB) stop returning 404, but visitors
will see placeholder text. To publish them only after the client has entered the real
text, use `-- --unpublished` and let the client publish each page in the admin UI.

Page content is **not** copied from staging to live. Edits made on staging must be
repeated on live (or entered directly on live). Never copy the staging database over live.

## Rollback

- Pages: delete them under *Inhalt* (admin/content), or restore the backup.
- Config: revert the commit, deploy, `drush cim -y`. Delete the pages first, because a
  content type that still has content cannot be removed.
