# Waterfall

A Pixiv-style image waterfall (masonry) sharing page for [Flarum](https://flarum.org) 2.x.

Users upload images to an external image host, the extension transfers them
asynchronously through the queue, and the resulting images are rendered in a
masonry grid with lazy loading, infinite scrolling, a native lightbox and
like-based ranking.

- **Package:** `lcoy/flarum-ext-waterfall`
- **Extension ID:** `lcoy-waterfall`
- **Namespace:** `Lcoy\Waterfall`
- **Frontend route:** `/waterfall`

---

## Features

- `/waterfall` forum route with server-side preloaded first page
- Greedy shortest-column masonry layout with responsive column count
- Lazy loading + skeleton loading placeholders
- Infinite scroll (`page[offset]` / `page[limit]` pagination)
- Dependency-free lightbox (keyboard, wheel/pinch zoom, drag pan, double-click reset)
- Liking with optimistic updates and idempotent back-end
- External image host upload via multipart, with exponential backoff retry
- Async transfer through Flarum's built-in queue system
- Per-user hourly / concurrent and site-wide per-minute rate limits
- Recommendation score (recency + likes + views, exponentially decayed)
- Admin settings panel and an upload log panel
- Three granular permissions: upload, like, moderate

---

## Requirements

- Flarum `^2.0`
- PHP `^8.1`
- A running queue worker (see [Queue configuration](#queue-configuration))
- An external image host that accepts multipart uploads (e.g. Chevereto, Lsky Pro,
  or a self-hosted gateway) **or** a relay gateway you control

> The extension never stores the final image locally. Uploaded bytes are staged in
> a non web-accessible spool (`storage/waterfall-tmp`) and are deleted right after
> the transfer to the image host (unless you enable "local relay" for auditing).

---

## Installation

```bash
composer require lcoy/flarum-ext-waterfall:"*"
```

If you are developing locally with a path repository, use instead:

```bash
composer require lcoy/flarum-ext-waterfall:"*@dev"
```

---

## Enabling & permissions

1. Enable the extension:

   ```bash
   php flarum extension:enable lcoy-waterfall
   ```

2. Run the migrations (Flarum normally does this automatically on enable):

   ```bash
   php flarum migrate
   ```

3. Open **Admin → Extensions → Waterfall** and grant the permissions:

   | Ability                      | Meaning                              |
   | ---------------------------- | ------------------------------------ |
   | `lcoy-waterfall.upload`      | Allow uploading images               |
   | `lcoy-waterfall.like`        | Allow liking images                  |
   | `lcoy-waterfall.moderate`    | View upload logs / errors, delete    |

4. Configure the image host settings (see below).

---

## Image host configuration

The transfer is a `multipart/form-data` POST to the URL configured in
`lcoy-waterfall.upload_url`, with the file sent under the `file` field name.

### Common hosts

**Chevereto V4** (API key upload):

```curl
curl -X POST "https://img.example.com/api/1/upload" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "source=@/path/to/image.jpg"
```

Configure in the extension:

- **Upload URL:** `https://img.example.com/api/1/upload`
- **Extra params (JSON):** `{"key":"YOUR_API_KEY","format":"json"}`

**Lsky Pro** (token upload):

```curl
curl -X POST "https://img.example.com/api/v1/upload" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -F "file=@/path/to/image.jpg"
```

Configure in the extension:

- **Upload URL:** `https://img.example.com/api/v1/upload`
- **Extra params (JSON):** `{"format":"json"}`
- **Extra headers (JSON):** `{"Authorization":"Bearer YOUR_TOKEN","Accept":"application/json"}`

**Generic basic-auth protected endpoint:**

Set `basic_auth_user` / `basic_auth_pass` when the host uses HTTP Basic auth.

The `extra_params` and `extra_headers` settings accept a JSON object that is
merged into the request (as multipart form fields and HTTP headers
respectively). The transfer is considered successful when the response JSON
contains a `src` key (top-level object or first array element); an optional
`thumb` key is used as the thumbnail, falling back to `src`.

Upload and score jobs are dispatched through **Flarum's built-in queue system**
onto the default queue — no extension-specific queue or worker setup. The queue
driver (`sync`, `database`, `redis`, `file`, …) is whatever the site is
configured with; see the Flarum documentation on queues for driver
configuration and running `php flarum queue:work`.

The site-wide per-minute transfer quota defers (rather than drops) jobs when the
limit is hit: the job is re-released for the next minute bucket and this is
recorded as `deferred` in the upload log.

---

## Settings reference

All settings are namespaced with `lcoy-waterfall.` and editable in the admin UI.

| Setting                         | Default                 | Description                                   |
| ------------------------------- | ----------------------- | --------------------------------------------- |
| `upload_url`                    | `https://your.domain/upload` | Image host multipart endpoint           |
| `extra_params`                  | `""`                    | JSON object merged into the form fields       |
| `extra_headers`                 | `""`                    | JSON object merged into the request headers   |
| `basic_auth_user`               | `""`                    | HTTP Basic auth username                      |
| `basic_auth_pass`               | `""`                    | HTTP Basic auth password                      |
| `mime_whitelist`                | `jpg,jpeg,png,gif,webp` | Comma-separated whitelist (magic-byte check)  |
| `max_size_mb`                   | `10`                    | Max upload size in megabytes                  |
| `user_hourly_limit`             | `20`                    | Max uploads per user per hour                 |
| `global_per_minute_limit`       | `60`                    | Site-wide transfer quota per minute           |
| `user_concurrent_uploads`       | `3`                     | Max concurrent pending uploads per user       |
| `upload_timeout`                | `30`                    | Transfer timeout in seconds                   |
| `weight_likes`                  | `1.0`                   | Score: likes weight                           |
| `weight_views`                  | `0.3`                   | Score: views weight                           |
| `weight_recency`                | `1.0`                   | Score: recency weight                         |
| `decay_lambda`                  | `0.05`                  | Score: exponential decay factor               |
| `per_page`                      | `24`                    | Default images per page                       |
| `card_radius`                   | `8`                     | Card border radius (px)                       |
| `card_gutter`                   | `12`                    | Card gutter (px)                              |
| `show_like_button`              | `true`                  | Show the like button                          |
| `local_relay`                   | `false`                 | Keep a relay copy in `storage/waterfall-relay` |
| `poll_interval`                 | `5`                     | Frontend poll interval for pending uploads    |

---

## Recommendation algorithm

Each image has a `score` used by the "popular" sort:

```
score = weight_recency * exp(-decay_lambda * age_days)
      + weight_likes   * log1p(likes_count)
      + weight_views   * log1p(views_count)
```

Scores are recalculated off the hot path via `RecalculateScoreJob` whenever an
image is uploaded, liked/unliked or viewed.

---

## API

The extension exposes a JSON:API resource of type `waterfall-images`.

| Method | Route                                | Description                      |
| ------ | ------------------------------------ | -------------------------------- |
| GET    | `/api/waterfall-images`              | List (paginated, sortable)       |
| GET    | `/api/waterfall-images/{id}`         | Show one image                   |
| POST   | `/api/waterfall-images`              | Upload (multipart, authenticated)|
| POST   | `/api/waterfall-images/{id}/like`    | Like                             |
| DELETE | `/api/waterfall-images/{id}/like`    | Unlike                           |
| POST   | `/api/waterfall-images/{id}/view`    | Record a view                     |
| DELETE | `/api/waterfall-images/{id}`         | Delete (owner or moderator)      |

Supported sort fields: `-createdAt` / `-created_at`, `-likesCount` / `-likes_count`,
`-score`, `createdAt` / `created_at`, etc.

### Upload example

```bash
curl -X POST "https://forum.example.com/api/waterfall-images" \
  -H "Authorization: Token YOUR_API_TOKEN" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My cat" \
  -F "width=1920" \
  -F "height=1080"
```

The response is `201` with a `waterfall-images` resource in `pending` status.
The frontend polls until the transfer completes and the image flips to
`published` (or `failed` with an `error` message).

---

## Development

```bash
# Install frontend dependencies and build the JS bundles
cd js
yarn install
yarn run build        # emits js/dist/forum.js and js/dist/admin.js
yarn run build-dev    # development watch build
yarn run check-typings
yarn run lint
```

The webpack root entry points are `js/forum.ts` and `js/admin.ts`; they re-export
from `js/src/common`, `js/src/forum` and `js/src/admin`.

### Tests

```bash
# One-time: create the integration test database
composer test:setup

# Run integration tests
composer test
```

Frontend unit tests:

```bash
cd js
yarn test
```

---

## Troubleshooting

**Uploads stay in "pending" forever**
Jobs run through Flarum's queue system. If the site's queue driver is not
`sync`, make sure a queue worker (`php flarum queue:work`) is running and the
driver (e.g. `redis`, `database`, `file`) is configured correctly per the
Flarum queue documentation. Also check `storage/logs/` for exceptions.

**Upload fails with `mime_not_allowed`**
The detected MIME type (from magic bytes, not the file extension) is not in
`mime_whitelist`. WebP, animated GIFs and HEIC are common offenders.

**Upload fails with `unsupported_response` / `missing_src`**
The image host returned an unexpected shape. Verify `upload_url`, `extra_params`,
`extra_headers`, and that the response JSON contains a `src` key. Enable
`local_relay` temporarily and inspect the returned payload in the upload log.
Upload log rows older than 3 days are pruned automatically.

**Site-wide quota `deferred` statuses accumulate**
The per-minute transfer limit is reached. Raise `global_per_minute_limit` or
scale your queue workers.

**I changed a forum-facing setting but the frontend still shows the old value**
Settings marked as "serialized to forum" clear the JS cache on save. If you changed
them directly in the database, clear the cache with `php flarum cache:clear`.

**Build fails with "No JS entrypoints could be found"**
`flarum-webpack-config` expects `js/forum.ts` and `js/admin.ts` at the root of the
`js/` directory. Ensure both exist.