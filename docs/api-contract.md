# API contract: Florank backend ↔ WordPress plugin

Source of truth on the backend side: `projection-agency/audit-back`,
`src/modules/publishing/adapters/wordpress.adapter.ts` (+ `wordpress-media.util.ts`).
This document describes the contract as of plugin **2.1.0**. Routes, request bodies, response shapes and the key
are the same as in **1.4.0**; 2.1.0 adds 403 responses (see "Run-as user") and limits image imports.
Any change here must stay backward compatible with sites that have not updated the plugin
(and with backends that do not know about a new field yet).

## Transport

- Base: `{baseUrl}/wp-json/florank/v1` (backend constant `PLUGIN_PREFIX = '/wp-json/florank/v1'`).
  `baseUrl` is the site URL the user entered in Florank, without trailing slash.
- JSON in and out. Backend sends `Content-Type: application/json` and `Accept: application/json`.
- Backend timeout: 90 s per request (image bytes can make bodies large).
- Sites with plain permalinks (`?rest_route=`) are not supported by the backend: it always calls `/wp-json/...`.

## Authentication

Every route requires the API key. The backend sends it in **both** headers:

```
Authorization: Bearer fl_<48 hex>
X-Florank-Key: fl_<48 hex>
```

Plugin side (`Auth\ApiKey::extract`):

1. `Authorization` matching `/^Bearer\s+(\S+)/i` → key.
2. Otherwise `X-Florank-Key` (trimmed). Needed for hosts that strip `Authorization`.

Key is checked with `wp_check_password()` against option `florank_api_key_hash`
(written by `wp_hash_password()`). Missing key, empty stored hash or mismatch →

```
HTTP 401
{ "code": "florank_unauthorized", "message": "Invalid Florank API key", "data": { "status": 401 } }
```

(1.x message text was "Invalid FLORANK API key"; the backend does not parse the text.)

Key format: `fl_` + `bin2hex(random_bytes(24))` → 51 chars. The UI hint is the first 7 chars + `…`.

### Run-as user (2.1.0+)

After the key is accepted, the request runs as the WordPress user stored in option `florank_run_as_user`
(`wp_set_current_user`), and WordPress capability checks apply. 1.x substituted the first administrator instead.

- Set when a key is created on the plugin page (the administrator who clicked "Create key"); the site owner can change it
  to any user with `edit_posts` and `upload_files`.
- Keys created by 1.x have no stored user: on the first authorized request (or the first admin page load) after the update
  the first administrator (lowest ID) is stored **once**. Later changes are never overwritten.
- Successful authorization stores the time in option `florank_last_request` (shown as "Connected").

New errors (all routes, after a valid key):

```
HTTP 403 { "code": "florank_user_missing",   "message": "The WordPress user Florank publishes as does not exist. …" }
HTTP 403 { "code": "florank_user_forbidden", "message": "The WordPress user Florank publishes as (login) cannot edit posts or upload files. …" }
```

Per operation (only possible when the site owner picked a non-administrator):

- `POST /posts` with `status` `publish`/`future` needs `publish_posts`; `POST /posts/{id}` on an existing post needs
  `edit_post`; `DELETE /posts/{id}` on an existing post needs `delete_post` →
  otherwise `HTTP 403 { "code": "florank_forbidden", … }`. Unknown IDs keep the 1.4 responses (500 / 400).
- `author_email` is applied only if it is the run-as user or the run-as user has `edit_others_posts`; otherwise ignored.

Messages are translated to the site language; the backend shows them as is (`HTTP 403: {body}`).

## Stored data (must not be renamed — upgrade compatibility)

| Name | Kind | Content |
|---|---|---|
| `florank_api_key_hash` | option | password hash of the API key |
| `florank_api_key_hint` | option | `fl_xxxx…` |
| `florank_new_api_key` | transient, 10 min | plain key right after generation (shown once) |
| `florank_indexnow_key` | option, autoload no | IndexNow key |
| `_florank_schema_json_ld` | post meta | JSON-encoded schema from `schema_json_ld` |
| `florank_run_as_user` | option (2.1.0+) | ID of the user requests run as; `0` if no administrator was found during migration |
| `florank_last_request` | option (2.1.0+) | Unix time of the last authorized request; cleared on new / revoked key |
| `_florank_managed` | post meta (2.1.0+) | `1` on posts created or updated through the API (plugin page list) |
| `_florank_source_url`, `_florank_source_hash` | attachment meta (2.1.0+) | source URL and SHA-256 of imported images (reuse on update) |
| `_yoast_wpseo_title`, `_yoast_wpseo_metadesc` | post meta | SEO title / description (Yoast) |
| `rank_math_title`, `rank_math_description` | post meta | SEO title / description (Rank Math) |

## Post object (response shape of all `/posts` routes)

```json
{
  "id": 123,
  "title": "Decoded title",
  "slug": "post-slug",
  "status": "draft",
  "link": "https://site.example/?p=123",
  "modified": "2026-11-04 10:00:00",
  "featured_media": 456
}
```

- `title` — `get_the_title()` with HTML entities decoded.
- `link` — `get_permalink()`; for drafts this is the `?p=` form.
- `modified` — `post_modified_gmt` (`Y-m-d H:i:s`, GMT, no timezone suffix).
- `featured_media` — attachment ID, `0` when none.

Backend uses: `id` → `externalId`, `link` → `externalUrl` (fallback `{baseUrl}/?p={id}`),
and for lists also `title`, `slug`, `status`, `modified`.

## Routes

### `GET /health`

Response 200:

```json
{ "ok": true, "plugin": "florank", "version": "2.1.0", "seoPlugin": "yoast", "siteUrl": "https://site.example/" }
```

- `seoPlugin`: `"rankmath"` if `RANK_MATH_VERSION` is defined or class `RankMath` exists; else `"yoast"` if
  `WPSEO_VERSION` is defined or class `WPSEO_Meta` exists; else `"none"`. Rank Math wins when both are active.
- Backend reads `seoPlugin` (anything other than `yoast` / `rankmath` is treated as `none`). `version` is not read yet
  (planned in stage 3: "update the plugin" warning).

### `GET /posts`

Up to 50 posts of type `post` with status `publish`, `draft` or `future`, ordered by `modified DESC`.
Response 200: array of post objects.

### `GET /posts/{id}`

`{id}` matches `\d+`. Response 200: post object.
Not a post / not found → `404 { "code": "florank_not_found", "message": "Post not found" }`.
Backend uses it to resolve `link` for SEO snapshots.

### `POST /posts` — create

### `POST /posts/{id}` — update

Same body for both. Response 200: post object of the created / updated post.

Request body (as built by `WordpressAdapter.pluginBody`):

| Field | Type | Plugin handling |
|---|---|---|
| `title` | string | `sanitize_text_field` → `post_title` |
| `slug` | string | `sanitize_title` → `post_name` |
| `content` | string (HTML, or Markdown if no HTML) | `wp_kses_post` → `post_content` |
| `excerpt` | string | `sanitize_textarea_field` → `post_excerpt` |
| `status` | `"draft"` \| `"publish"` (plugin also accepts `"future"`) | `sanitize_key`; anything else → `draft` |
| `meta_title` | string | `sanitize_text_field`; falls back to `title` when missing; written to Yoast and Rank Math meta |
| `meta_description` | string | `sanitize_textarea_field`; written to Yoast and Rank Math meta |
| `featured_image` | packed image, optional | imported as bytes → featured image |
| `featured_image_url` | string, optional | used only if `featured_image` is missing or failed: downloaded only from allowed hosts (see Images) |
| `featured_image_alt` | string, optional | alt text of the featured image (`_wp_attachment_image_alt`) |
| `content_images` | packed image[] | each imported as bytes; every occurrence of its `sourceUrl` in content is replaced with the local URL |
| `categories` | int[] | `wp_set_post_categories` when non-empty (from connection config `categoryIds`) |
| `schema_json_ld` | object or array, optional (plugin ≥ 1.3) | stored in `_florank_schema_json_ld` |
| `indexnow_key` | string, optional (plugin ≥ 1.4) | stored if it matches `^[a-zA-Z0-9-]{8,128}$`, otherwise ignored |
| `author_email` | string, optional (plugin ≥ 1.4) | if a WP user has this email, they become `post_author` |

Packed image (`PackedWpImage` in `wordpress-media.util.ts`, max 8 MB before base64):

```json
{ "sourceUrl": "https://…/image.png", "filename": "image.png", "mime": "image/png", "data": "<base64>", "alt": "" }
```

- `filename` → `sanitize_file_name` (default `image.png`). `mime` is not used by the plugin (the real type is detected
  with `wp_check_filetype_and_ext`; a wrong extension is corrected).
- The same `sourceUrl` is imported once per request.

After bytes are processed, any remaining `<img src="…">` and Markdown `![](http…)` URLs in the content are
downloaded (only from allowed hosts, skipping URLs already in this site's uploads), and the content is
rewritten to local URLs.

### Images (2.1.0+)

- Remote URLs (`featured_image_url`, URLs left in content) are fetched only over `https` (port 443, no credentials, no
  redirects) from hosts in filter `florank_allowed_image_hosts`, default `*.blob.vercel-storage.com` (Vercel Blob, where
  the backend stores article images — `LocalImageStorage`), `florank.ai`, `api.florank.ai`. Other URLs are skipped
  silently and stay in the content as they are. Packed images (bytes) are accepted from any `sourceUrl`.
- Max size 10 MB (filter `florank_max_image_bytes`); backend packs up to 8 MB, so packed images always fit.
- Only `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/avif` (real type). Anything else is skipped silently.
- Reuse: an attachment with the same `_florank_source_url`, or the same SHA-256 of bytes, is reused instead of importing
  again (updates no longer create duplicates). Deleted attachments are imported again.

Errors: `wp_insert_post` / `wp_update_post` `WP_Error` is returned as is. Example: `POST /posts/999999` (unknown ID) →
`500 { "code": "invalid_post", "message": "Invalid post ID.", "data": null }` (same in 1.4.0).
Image import failures are silent: the post is saved without that image.

Order of operations: key check → run-as user (403 if missing / no rights) → capability checks → post saved →
`_florank_managed` → SEO meta → IndexNow key → author → JSON-LD → categories → featured image → content images →
content rewrite.

Change visible to the backend: in 1.x posts created without a matching `author_email` had `post_author = 0`;
from 2.1.0 the author is the run-as user.

### `DELETE /posts/{id}`

Permanently deletes (`wp_delete_post( $id, true )`). Response 200: `{ "ok": true, "id": 123 }`.
Failure → `400 { "code": "florank_delete_failed", "message": "Could not delete post" }`.

## Backend flows

- **Connection test** (`testConnection`): `GET /health` → `POST /posts`
  `{ title: "FLORANK connection test", content: "<!-- florank connection test -->", status: "draft", excerpt: "FLORANK test" }`
  → `DELETE /posts/{id}` (failure only logged).
- **Publish**: `POST /posts`. **Update**: `POST /posts/{externalId}`.
- **List existing content**: `GET /posts`.
- **SEO snapshot**: `GET /posts/{id}` → fetch `link` HTML.

Backend error handling (`pluginRequest`):

- HTTP 404 on any route → "plugin not found on the site" (so a missing plugin, a missing route and an unknown post ID look the same).
- Other non-2xx → `WordPress {METHOD} {path} → HTTP {status}: {first 400 chars of body}`.
- Empty 2xx body → `{}`.

## Outside the REST API

- `wp_head` on single posts: prints each stored JSON-LD item as `<script type="application/ld+json">`, with
  `url`, `mainEntityOfPage`, `datePublished`, `dateModified` and `image` (featured image) of `BlogPosting` items
  replaced by real WordPress values. Skipped when Yoast (`WPSEO_VERSION`) or Rank Math (`RankMath` /
  `RANK_MATH_VERSION`) is active.
- `init` (priority 0): request path `/{indexnow_key}.txt` → `text/plain` response with the key.

## Without the plugin (for reference)

The backend also supports core REST with Application Passwords (`/wp-json/wp/v2/posts`, Basic auth). That mode
does not use this plugin and is not part of this contract.
