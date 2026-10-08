# ImmoFirst API (`immofirst_api`)

Generic, token-authenticated JSON API for CRUD and bulk operations on **any
Drupal content entity type** (node, user, taxonomy_term, file, media,
comment, block_content, paragraph, custom entities, …), discovered at
runtime through the Entity API. No entity type, bundle or field is
hard-coded, and no SQL is written by hand.

## Setup

1. Create a dedicated Drupal user (e.g. `api-service`) and give it a role
   with **"Use the ImmoFirst entity API"** plus exactly the content
   permissions the API should have (e.g. "administer nodes"). Every request
   runs as this account, so normal entity, field and query access applies.
2. Set the environment variables on each environment (web server **and**
   CLI). They're read in `settings.php`:

   ```
   IMMOFIRST_API_TOKEN=<random, at least 32 characters, e.g. openssl rand -hex 32>
   IMMOFIRST_API_UID=<uid of the service account>
   ```

   Alternatively set `$settings['immofirst_api_token']` and
   `$settings['immofirst_api_uid']` in the git-ignored
   `settings.local.php`. Never commit the token.
3. Enable the module and export config (`core.extension`).

Optional settings:

| Setting | Default | Meaning |
|---|---|---|
| `immofirst_api_denied_entity_types` | `[]` | Entity type ids the API must not touch at all. |
| `immofirst_api_allow_user_delete` | `FALSE` | Allow deleting users (see "User deletion" below). |

## Authentication

```
Authorization: Bearer <IMMOFIRST_API_TOKEN>
```

A missing or invalid token gets a `401`. A valid token whose account lacks
access gets a `403`. Ten failed attempts per IP in 15 minutes, or more than
600 requests per IP per minute, get a `429`.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/entity-types` | Supported entity types with their keys, bundles, revision/translation support. |
| GET | `/api/v1/entity-types/{type}` | Same for one type, plus its field definitions. |
| GET | `/api/v1/entity/{type}` | Collection: `limit` (max 100), `offset` / `page`, `ids=1,2`, `bundle`, `langcode`, `sort=field,-field2`, `filter[field]=v`, `filter[field][value]=v&filter[field][operator]=>`. |
| POST | `/api/v1/entity/{type}` | Create (JSON object of field values; bundle key required for bundled types). |
| GET | `/api/v1/entity/{type}/{id}` | Read (`?langcode=` for a translation). |
| PATCH | `/api/v1/entity/{type}/{id}` | Update the given fields (`?langcode=` for a translation). |
| DELETE | `/api/v1/entity/{type}/{id}` | Delete. |
| POST | `/api/v1/entity/{type}/bulk` | `{"operation":"create","items":[…]}`, `delete_all`, `update_all`. |
| PATCH | `/api/v1/entity/{type}/bulk` | `{"operation":"update","items":[{"id":1,"fields":{…}}]}`. |
| DELETE | `/api/v1/entity/{type}/bulk` | `{"operation":"delete","ids":[1,2,3]}`. |

The filter operators are `=`, `<>`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`,
`CONTAINS`, `STARTS_WITH`, `ENDS_WITH`, `IS NULL` and `IS NOT NULL`. Filters
and sorts take `field` or `field.property` (e.g. `uid.target_id`), on fields
the account may view.

Every request body must be `Content-Type: application/json` and at most
2 MB. A bulk request takes at most 100 items.

### Whole collection: `delete_all` / `update_all`

```json
POST /api/v1/entity/node/bulk
{ "operation": "delete_all", "confirm": true, "bundle": "search_request", "max": 1000 }
```

- `confirm: true` is mandatory. `bundle` is optional. `max` sets entities
  per call (default 1000, maximum 5000).
- The API processes entities in batches of 50 by ascending id, loading one
  batch at a time. Each call stops after `max` entities or about 20 seconds.
- The response reports `processed`, `failed`, up to 50 `failures`,
  `remaining`, `last_id` and `complete`. While `complete` is `false`, call
  again with `"after_id": <last_id>`.
- `update_all` also takes `"fields": {…}`.
- Only one `*_all` run per entity type at a time; a second one gets a `409`.

## Responses

```json
{ "success": true, "data": { … }, "errors": [] }
```

A bulk response's `data` holds `created`, `updated`, `deleted` and `failed`,
with a per-item `index`, `id`, `status`, `message` and `errors`. Bulk
operations are not atomic: each item succeeds or fails on its own.

| Status | Meaning |
|---|---|
| 200 / 201 | OK / created |
| 400 | Malformed request, bad parameters or invalid JSON |
| 401 | Missing or invalid token |
| 403 | Entity or field access denied, or a protected operation |
| 404 | Unknown or unsupported entity type, or entity not found |
| 409 | Unique-key conflict, or a `*_all` run already in progress |
| 413 | Body larger than 2 MB |
| 415 | Body isn't `application/json` |
| 422 | Unknown or read-only field, invalid value, or validation errors |
| 429 | Rate limit hit |
| 500 | Unexpected error (details only in the log) |

## Field rules

- **Unknown fields are rejected**, never silently ignored.
- **Never writable:** computed and read-only fields, the id, uuid and
  revision keys, revision metadata, and translation bookkeeping fields. On
  update, the bundle and langcode can't be changed either.
- **Field edit access is checked** per field, and the entity is validated
  with `$entity->validate()` before saving.
- **Values must be plain JSON data** (scalars, arrays, objects) and are set
  through the field's typed data.
- **Password fields are never returned.** Reads include only fields the
  account may view.
- **Revisions:** an update creates a new revision when the bundle is
  configured for it (`shouldCreateNewRevision()`), with the service account
  as the revision author.

## Safety guards

- **Supported types:** content entity types only. Config entities, internal
  types and types with null storage are excluded.
- **File entities:** `uri` must be `public://` or `private://` without `..`
  and can't be changed after creation. A file entity whose URI is outside
  those schemes can't be deleted through the API.
- **User deletion is disabled by default.** Deleting a user through the
  Entity API also deletes all nodes that user owns (core
  `NodeEntityHooks::userPredelete`).
- **Protected accounts:** uid 0, uid 1 and the service account can never be
  changed or deleted through the API. That prevents admin lock-out (e.g.
  `update_all` with `status: 0`) and admin account takeover.
- **No caching:** page cache never serves or stores `/api/v1/*` responses,
  and every response sends `Cache-Control: no-store`.
- **Logging:** the token is never logged. Write operations are logged to the
  `immofirst_api` channel with entity type, id and acting uid.
- **No upload or execution:** there is no file upload, and no endpoint
  executes PHP or SQL.
