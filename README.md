# Waterfall / 瀑布流

A Pixiv-style image gallery for [Flarum](https://flarum.org) 2.x: uploads are
relayed to an external image host and rendered as a masonry feed with a
lightbox, likes, tags and a recommendation feed.

一个面向 [Flarum](https://flarum.org) 2.x 的 Pixiv 风格图片瀑布流扩展：图片异步转存到外部图床,
以瀑布流卡片展示,支持灯箱看图、点赞、标签与推荐排序。

[English](#english) · [中文](#中文)

---

# English

- **Package:** [`lcoy/waterfall`](https://packagist.org/packages/lcoy/waterfall)
- **Extension ID:** `lcoy-waterfall`
- **Namespace:** `Lcoy\Waterfall`
- **Forum route:** `/waterfall`
- **Source:** <https://gitee.com/lcoy/Flarum-Waterfall> (mirrored to
  <https://github.com/Lcoy2004/Flarum-Waterfall>, which is what Packagist
  tracks)

## What it does

One upload session (one or more files chosen together) becomes one **image
set**. The feed lists sets, one card per set; opening a card browses that set's
images in a lightbox.

- `/waterfall` route with the first page preloaded server-side (fast LCP)
- Feed cards in a fixed 3:4 portrait frame, laid out with **CSS Grid**
  (`repeat(auto-fill, minmax(min(280px, 45%), 1fr))`) — automatic responsive
  column count, no JS measuring or virtual scrolling
- Native browser lazy-loading, skeleton placeholders, infinite scroll
- Optional cover slideshow per card (crossfades through the set's first images)
- Dependency-free lightbox: keyboard navigation, wheel/pinch zoom, drag pan,
  swipe or arrows to browse, double-click reset, per-image likes and delete
- Freeform tags per set (up to 5 tags, 20 characters each)
- Inline title rename (owner or moderator)
- Uploads relayed to an external image host asynchronously through Flarum's
  queue, with exponential-backoff retry
- Browser-side card thumbnail so the grid does not download full-size originals
- Per-user hourly / concurrent limits and a site-wide per-minute transfer quota
- Recommendation score from likes, views and recency
- Admin settings panel, a one-click image host check and an upload log panel

### How uploads flow

1. The browser creates the set (`POST /api/waterfall-sets`) and, for each file,
   encodes a card-sized copy (WebP, JPEG fallback; skipped for GIFs, files under
   150 KB, or images already within 800 px).
2. Each file is posted to `POST /api/waterfall-images` as multipart
   (`file`, plus `thumb`, `title`, `set_id`, `position`).
3. The bytes are staged in `storage/waterfall-tmp` (not web-accessible) and a
   queued job is pushed. The API answers immediately with a `pending` image.
4. The queue worker transfers the file (and the card copy) to the image host,
   then publishes the image: `src`, `thumb`, `status = published`, `score`.
5. The frontend polls pending sets until they resolve to `published` or
   `failed`.

The extension never hosts the final image itself.

## Requirements

- Flarum `^2.0`
- PHP `^8.3` (the version Flarum 2.x requires)
- A queue worker running if your queue driver is not `sync`
- An external image host that accepts a `multipart/form-data` POST with the file
  under the **`file`** field (see [Image host setup](#image-host-setup))

## Installation

```bash
composer require lcoy/waterfall
```

Then enable it and run the migrations:

```bash
php flarum extension:enable lcoy-waterfall
php flarum migrate
```

### Installing without Packagist

To track the repository directly instead — for example to pin to a branch — add
it as a VCS repository:

```bash
composer config repositories.waterfall vcs https://gitee.com/lcoy/Flarum-Waterfall.git
composer require lcoy/waterfall:"*@dev"
```

Tagged releases are also attached as a self-contained zip (no `vendor/`, no
`js/node_modules`) on the [GitHub releases
page](https://github.com/Lcoy2004/Flarum-Waterfall/releases), for hosts where
Composer cannot be run.

### Developing locally

To work on a checkout as a symlinked path package, add a path repository in the
site's `composer.json` and require it:

```json
{
  "repositories": [
    {
      "name": "lcoy-waterfall",
      "type": "path",
      "url": "vendor/lcoy/waterfall",
      "options": { "symlink": true }
    }
  ]
}
```

```bash
composer require lcoy/waterfall:"@dev"
composer dump-autoload
```

The compiled bundle in `js/dist/` is committed, so the extension runs without a
frontend build step.

## Permissions

Grant them in **Admin → Permissions**.

| Ability                   | Grants                                                                            |
| ------------------------- | --------------------------------------------------------------------------------- |
| `lcoy-waterfall.upload`   | Create sets and upload images                                                     |
| `lcoy-waterfall.like`     | Like and unlike images                                                            |
| `lcoy-waterfall.moderate` | Delete or rename anyone's sets and images, read upload error messages, attach images to anyone's set |

The upload log panel is **admin-only** (`admin` permission), not part of
`lcoy-waterfall.moderate`.

Visibility: published sets and images are public (including to guests). A user
additionally sees their own `pending` and `failed` rows, and the `error` text on
an image is only exposed to its owner and to moderators.

## Image host setup

A transfer is a `multipart/form-data` `POST` to
`lcoy-waterfall.upload_url`. **The file field name is fixed to `file`** — it is
not configurable, so the host must accept that name.

Authentication and extra fields are configured with settings:

- `basic_auth_user` / `basic_auth_pass` → `Authorization: Basic …`
- `extra_headers` → arbitrary HTTP headers, as a JSON object
- `extra_params` → extra multipart fields, as a JSON object

```bash
# Equivalent of what the extension sends, for a host that wants a bearer token
# and an extra form field:
curl -X POST "https://img.example.com/api/v1/upload" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -F "file=@/path/to/image.jpg" \
  -F "format=json"
```

Configure that as:

- **Upload URL:** `https://img.example.com/api/v1/upload`
- **Extra headers (JSON):** `{"Authorization":"Bearer YOUR_TOKEN","Accept":"application/json"}`
- **Extra params (JSON):** `{"format":"json"}`

### Expected response

A `200` response whose JSON contains a `src` key, either as a top-level object
or as the first element of an array:

```json
[{ "src": "/file/abc.webp", "thumb": "/file/abc.thumb.webp" }]
```

A root-relative `src` (`/file/abc.webp`) is resolved against the configured
upload URL. An optional `thumb` key is used as the card image; when it is
missing, the card falls back to `src`.

Use **Admin → Extensions → Waterfall → Test image host** to probe the configured
endpoint with the real auth and headers. It reports whether the credentials were
accepted, rejected, or the endpoint is wrong.

## Settings

All keys are namespaced with `lcoy-waterfall.`.

| Setting                   | Default                      | Description                                                            |
| ------------------------- | ---------------------------- | ---------------------------------------------------------------------- |
| `upload_url`              | `https://your.domain/upload` | Image host multipart endpoint. **Replace the placeholder.**             |
| `extra_params`            | `""`                         | JSON object merged in as extra multipart fields                         |
| `extra_headers`           | `""`                         | JSON object merged in as request headers                                |
| `basic_auth_user`         | `""`                         | HTTP Basic auth username                                                |
| `basic_auth_pass`         | `""`                         | HTTP Basic auth password                                                |
| `mime_whitelist`          | `jpg,jpeg,png,gif,webp`      | Allowed formats, matched against sniffed magic bytes                    |
| `max_size_mb`             | `10`                         | Maximum upload size in megabytes                                        |
| `user_hourly_limit`       | `20`                         | Maximum uploads per user per hour (`0` disables)                        |
| `user_concurrent_uploads` | `3`                          | Maximum uploads left pending per user (`0` disables)                    |
| `global_per_minute_limit` | `60`                         | Site-wide transfers per minute; over-quota jobs are deferred, not dropped |
| `upload_timeout`          | `30`                         | Transfer timeout in seconds                                             |
| `per_page`                | `24`                         | **Sets** per feed page (1–100)                                          |
| `card_radius`             | `8`                          | Card corner radius (px)                                                 |
| `card_gutter`             | `12`                         | Gap between cards (px)                                                  |
| `show_like_button`        | `true`                       | Show the like button in the lightbox                                    |
| `slideshow_images`        | `3`                          | Images per card slideshow; `0` or `1` disables it (cover only)           |
| `poll_interval`           | `5`                          | Frontend poll interval for pending uploads, in seconds                  |
| `local_relay`             | `false`                      | Keep a copy of each upload in `storage/waterfall-relay` for auditing    |

The MIME check reads the file's magic bytes, never the client-supplied type or
extension, so renaming an executable to `.jpg` does not get it through. SVG is
not supported: it is sniffed as `image/svg+xml`, and since the whitelist matches
the MIME subtype, listing `svg` alone does not enable it.

## Recommendation score

The "popular" sort uses each image's `score` (rounded to 4 decimals):

```
score = weight_likes   * log10(1 + likes_count)
      + weight_views   * log10(1 + views_count)
      + weight_recency * exp(-decay_lambda * age_hours)
```

`age_hours` is the image's age in hours. A set's score is the sum of its images'
scores.

Recalculation is off the request path. Uploads compute the score as part of the
publish write; likes, unlikes and views dispatch `RecalculateScoresJob`, which
carries a batch of image ids (a whole lightbox page arrives as one job) and
re-syncs each affected set once. Views are coalesced to at most one
recalculation per image per minute.

## API

Two JSON:API resources back the feature: `waterfall-sets` (the feed) and
`waterfall-images` (the individual pictures).

### `waterfall-sets`

| Method | Route                        | Notes                                              |
| ------ | ---------------------------- | -------------------------------------------------- |
| GET    | `/api/waterfall-sets`        | List sets (the feed). Default sort `-createdAt`     |
| GET    | `/api/waterfall-sets/{id}`   | Show one set                                        |
| POST   | `/api/waterfall-sets`        | Create a set (needs `lcoy-waterfall.upload`)        |
| PATCH  | `/api/waterfall-sets/{id}`   | Update `title` / `tags` (owner or moderator)        |
| DELETE | `/api/waterfall-sets/{id}`   | Delete (owner or moderator)                         |

### `waterfall-images`

| Method | Route                             | Notes                                             |
| ------ | --------------------------------- | ------------------------------------------------- |
| GET    | `/api/waterfall-images`           | List images                                        |
| GET    | `/api/waterfall-images/{id}`      | Show one image                                     |
| POST   | `/api/waterfall-images`           | Upload (multipart, needs `lcoy-waterfall.upload`)  |
| DELETE | `/api/waterfall-images/{id}`      | Delete (owner or moderator)                        |
| POST   | `/api/waterfall-images/{id}/like` | Like (idempotent, needs `lcoy-waterfall.like`)     |
| DELETE | `/api/waterfall-images/{id}/like` | Unlike (idempotent)                                |
| POST   | `/api/waterfall-images/{id}/view` | Record a view (1 per IP per image per minute)      |
| POST   | `/api/waterfall-images/views`     | Record a batch of views (same counting rules)      |

### Other endpoints

| Method | Route                       | Notes                                       |
| ------ | --------------------------- | ------------------------------------------- |
| GET    | `/api/waterfall-upload-logs`| Transfer log, last 100 (admin only)         |
| POST   | `/api/waterfall/test-host`  | Image host connectivity check (admin only)  |

### Filters and sorts

- Sets: `filter[id]`, `filter[user]`; sorts `-createdAt`, `-score`, `-likesCount`
- Images: `filter[id]`, `filter[user]`, `filter[set]`; sorts `-createdAt`,
  `-score`, `-likesCount`, `position`

Both camelCase (`-createdAt`) and snake_case (`-created_at`) sort names are
accepted. Pagination is offset-based: `page[offset]` and `page[limit]` (max 100).

### Upload example

```bash
curl -X POST "https://forum.example.com/api/waterfall-images" \
  -H "Authorization: Token YOUR_API_TOKEN" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My cat" \
  -F "set_id=12" \
  -F "position=0"
```

The response is `201` with the image in `pending` status. `set_id` must belong to
the uploader (or the uploader must be a moderator); an image with no `set_id` is
allowed but will not appear in the feed.

## Storage and queue

- `storage/waterfall-tmp` — staging spool for bytes awaiting transfer. Files are
  removed right after the transfer.
- `storage/waterfall-relay` — only created when `local_relay` is enabled, which
  retains the staged original for auditing.

Jobs run on Flarum's **default queue**, so the existing
`php flarum queue:work` handles them and no extra worker is needed. The queue
driver (`sync`, `database`, `redis`, `file`, …) is whatever the site uses. When
the site-wide per-minute quota is exhausted the job is released back onto the
queue for the next minute and logged as `deferred`, never dropped.

Upload log rows are pruned opportunistically after 3 days.

## Development

```bash
cd js
yarn install
yarn build          # production bundle → js/dist/forum.js, js/dist/admin.js
yarn dev            # development build with a watcher
yarn check-typings  # tsc --noEmit
yarn format-check   # prettier --check src
yarn test           # Jest unit tests
```

The webpack entry points are `js/forum.ts` and `js/admin.ts`; they re-export
`js/src/common`, `js/src/forum` and `js/src/admin`.

PHP integration tests:

```bash
composer test:setup   # one-time: create the test database
composer test         # run the integration suite
```

## Troubleshooting

**Uploads stay in `pending` forever**
If the queue driver is not `sync`, make sure a worker is running
(`php flarum queue:work`). After 5 minutes of polling the frontend marks the set
failed locally; reloading the page reconciles with the server.

**Upload fails with "the file type is not allowed"**
The sniffed MIME type is not in `mime_whitelist`. The check uses magic bytes, so
the extension of the file does not matter. WebP and HEIC are common surprises.

**An image fails with one of these error codes (visible in the upload log)**
`image_host_not_configured` (upload URL empty or invalid),
`image_host_http_error` (non-200), `image_host_invalid_response` (response was
not JSON), `image_host_missing_src` (no `src` key),
`image_host_unreachable` (network/TLS failure after 3 attempts),
`staged_file_missing` (spool file vanished before the worker ran),
`unexpected_error` (a bug — check `storage/logs/`).

**Uploads are delayed with a `deferred` log status**
The per-minute transfer quota was hit. Raise `global_per_minute_limit` or add
queue workers.

**A forum-facing setting changed but the frontend still shows the old value**
Settings serialized to the forum (`per_page`, `card_radius`, `card_gutter`,
`show_like_button`, `slideshow_images`, `poll_interval`) clear the JS cache when
saved through the admin UI. If you changed one directly in the database, run
`php flarum cache:clear`.

**Build fails with "No JS entrypoints could be found"**
`flarum-webpack-config` expects `js/forum.ts` and `js/admin.ts` to exist.

## License

MIT — see [LICENSE](LICENSE).

---

# 中文

- **包名:** [`lcoy/waterfall`](https://packagist.org/packages/lcoy/waterfall)
- **扩展 ID:** `lcoy-waterfall`
- **命名空间:** `Lcoy\Waterfall`
- **前台路由:** `/waterfall`
- **源码:** <https://gitee.com/lcoy/Flarum-Waterfall>(镜像到
  <https://github.com/Lcoy2004/Flarum-Waterfall>,Packagist 跟踪的是后者)

## 功能概述

一次上传(一次选中的一个或多个文件)构成一个**图片集**。瀑布流以"集"为卡片单位展示,
点开卡片即在该集的图片间用灯箱浏览。

- `/waterfall` 路由,首页由服务端预加载(改善首屏 LCP)
- 卡片固定 3:4 竖版画框,使用 **CSS Grid** 布局
  (`repeat(auto-fill, minmax(min(280px, 45%), 1fr))`)——列数自适应,无需 JS 测量或虚拟滚动
- 浏览器原生懒加载、骨架占位、无限滚动
- 卡片封面幻灯片(在集内前几张图之间交叉淡入,可关闭)
- 零依赖灯箱:键盘导航、滚轮/双指缩放、拖拽平移、左右滑动或箭头切换、双击复位、单图点赞与删除
- 集级自由标签(最多 5 个,每个 20 字符)
- 可原地重命名标题(作者或版主)
- 上传经 Flarum 队列**异步转存**到外部图床,失败按指数退避重试
- 浏览器侧生成卡片缩略图,瀑布流不必下载原图
- 用户每小时/并发限额,以及全站每分钟转存配额
- 基于点赞、浏览与时效的推荐评分
- 后台设置面板、一键图床连通性检测、上传日志面板

### 上传流程

1. 浏览器先创建集(`POST /api/waterfall-sets`),并逐个文件生成卡片尺寸副本
   (优先 WebP,回退 JPEG;GIF、小于 150KB 或本身不超过 800px 的图跳过)。
2. 每个文件以 multipart 提交到 `POST /api/waterfall-images`
   (字段 `file`,以及可选的 `thumb`、`title`、`set_id`、`position`)。
3. 字节暂存到 `storage/waterfall-tmp`(不可通过 Web 访问)并推入队列任务,
   接口立即返回 `pending` 状态的图片。
4. 队列 worker 把文件(及卡片副本)转存到图床,然后发布该图片:
   `src`、`thumb`、`status = published`、`score`。
5. 前台轮询 pending 状态的集,直到变为 `published` 或 `failed`。

扩展本身**不保存**最终图片。

## 环境要求

- Flarum `^2.0`
- PHP `^8.3`(Flarum 2.x 的要求)
- 若队列驱动不是 `sync`,需要运行队列 worker
- 一个接受 `multipart/form-data` POST、且文件字段名为 **`file`** 的图床
  (见[图床配置](#图床配置))

## 安装

```bash
composer require lcoy/waterfall
```

然后启用并执行迁移:

```bash
php flarum extension:enable lcoy-waterfall
php flarum migrate
```

### 不走 Packagist 的安装方式

若希望直接跟踪仓库(例如锁定到某个分支),可把它注册为 VCS 仓库:

```bash
composer config repositories.waterfall vcs https://gitee.com/lcoy/Flarum-Waterfall.git
composer require lcoy/waterfall:"*@dev"
```

另外,每个标签发布时都会附带一个自包含 zip(不含 `vendor/`、不含
`js/node_modules`),见 [GitHub releases
页面](https://github.com/Lcoy2004/Flarum-Waterfall/releases),适用于无法运行
Composer 的主机。

### 本地开发

若要以软链接的 path 包形式开发,在站点的 `composer.json` 中加入 path 仓库:

```json
{
  "repositories": [
    {
      "name": "lcoy-waterfall",
      "type": "path",
      "url": "vendor/lcoy/waterfall",
      "options": { "symlink": true }
    }
  ]
}
```

```bash
composer require lcoy/waterfall:"@dev"
composer dump-autoload
```

`js/dist/` 中的构建产物已随仓库提交,因此无需前端构建步骤即可运行。

## 权限

在**后台 → 权限**中授予。

| 权限                      | 含义                                                                    |
| ------------------------- | ----------------------------------------------------------------------- |
| `lcoy-waterfall.upload`   | 创建集并上传图片                                                        |
| `lcoy-waterfall.like`     | 点赞与取消点赞                                                          |
| `lcoy-waterfall.moderate` | 删除/重命名他人的集与图片、查看上传错误信息、向他人的集上传图片          |

上传日志面板仅**管理员**可见(`admin` 权限),不属于 `lcoy-waterfall.moderate`。

可见性:已发布的集与图片对所有人(含游客)公开;用户额外可见自己的 `pending`
与 `failed` 记录;图片的 `error` 文案只对作者与版主暴露。

## 图床配置

转存为向 `lcoy-waterfall.upload_url` 发起的 `multipart/form-data` POST。
**文件字段名固定为 `file`**,不可配置,因此图床必须接受该字段名。

认证与附加字段由设置项提供:

- `basic_auth_user` / `basic_auth_pass` → `Authorization: Basic …`
- `extra_headers` → 任意 HTTP 头,JSON 对象
- `extra_params` → 额外的 multipart 字段,JSON 对象

```bash
# 等价于扩展发出的请求(示例:需要 Bearer 令牌与一个额外表单字段)
curl -X POST "https://img.example.com/api/v1/upload" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -F "file=@/path/to/image.jpg" \
  -F "format=json"
```

对应配置为:

- **上传地址:** `https://img.example.com/api/v1/upload`
- **额外请求头 (JSON):** `{"Authorization":"Bearer YOUR_TOKEN","Accept":"application/json"}`
- **额外参数 (JSON):** `{"format":"json"}`

### 期望的响应

返回 `200`,且 JSON 中包含 `src` 键——可以是顶层对象,也可以是数组的第一个元素:

```json
[{ "src": "/file/abc.webp", "thumb": "/file/abc.thumb.webp" }]
```

根相对路径的 `src`(`/file/abc.webp`)会按上传地址解析为绝对地址。可选的 `thumb`
用作卡片图;缺失时卡片回退使用 `src`。

可在**后台 → 扩展 → Waterfall → 测试图床**用真实的认证与请求头探测该地址,
它会告知凭证被接受、被拒绝,或地址有误。

## 设置项

所有键均以 `lcoy-waterfall.` 为前缀。

| 设置项                    | 默认值                       | 说明                                                          |
| ------------------------- | ---------------------------- | ------------------------------------------------------------- |
| `upload_url`              | `https://your.domain/upload` | 图床上传地址。**请替换该占位值。**                             |
| `extra_params`            | `""`                         | 作为额外 multipart 字段合并的 JSON 对象                        |
| `extra_headers`           | `""`                         | 作为请求头合并的 JSON 对象                                     |
| `basic_auth_user`         | `""`                         | HTTP Basic 认证用户名                                          |
| `basic_auth_pass`         | `""`                         | HTTP Basic 认证密码                                            |
| `mime_whitelist`          | `jpg,jpeg,png,gif,webp`      | 允许的格式,按嗅探到的魔数匹配                                   |
| `max_size_mb`             | `10`                         | 单文件最大体积(MB)                                             |
| `user_hourly_limit`       | `20`                         | 每用户每小时上传上限(`0` 表示不限制)                            |
| `user_concurrent_uploads` | `3`                          | 每用户同时处于 pending 的上传上限(`0` 表示不限制)               |
| `global_per_minute_limit` | `60`                         | 全站每分钟转存次数;超配额的任务会延迟而非丢弃                   |
| `upload_timeout`          | `30`                         | 转存超时(秒)                                                   |
| `per_page`                | `24`                         | 瀑布流每页**集**数(1–100)                                      |
| `card_radius`             | `8`                          | 卡片圆角(px)                                                   |
| `card_gutter`             | `12`                         | 卡片间距(px)                                                   |
| `show_like_button`        | `true`                       | 是否在灯箱显示点赞按钮                                          |
| `slideshow_images`        | `3`                          | 卡片幻灯片图片数;`0` 或 `1` 表示关闭(只显示封面)                |
| `poll_interval`           | `5`                          | 前台轮询 pending 状态的间隔(秒)                                 |
| `local_relay`             | `false`                      | 转存后在 `storage/waterfall-relay` 保留一份副本以便审计         |

MIME 校验读取文件魔数,不信任客户端提供的类型或扩展名,因此把可执行文件改名为
`.jpg` 无法通过。不支持 SVG:它会被嗅探为 `image/svg+xml`,而白名单匹配的是 MIME 子类型,
所以只写 `svg` 无法启用。

## 推荐评分

"热门"排序使用每张图片的 `score`(保留 4 位小数):

```
score = weight_likes   * log10(1 + likes_count)
      + weight_views   * log10(1 + views_count)
      + weight_recency * exp(-decay_lambda * age_hours)
```

`age_hours` 为图片已发布的小时数。集的评分等于其所有图片评分之和。

重算不在请求链路上:上传时随发布写入一并计算;点赞、取消点赞与浏览会派发
`RecalculateScoresJob`——它携带一批图片 id(一次灯箱浏览合成一个任务),并把
受影响的集各同步一次。浏览的重算被合并为每图每分钟至多一次。

## API

由两个 JSON:API 资源支撑:`waterfall-sets`(瀑布流主体)与
`waterfall-images`(单张图片)。

### `waterfall-sets`

| 方法   | 路由                         | 说明                                            |
| ------ | ---------------------------- | ----------------------------------------------- |
| GET    | `/api/waterfall-sets`        | 列出集(瀑布流)。默认排序 `-createdAt`           |
| GET    | `/api/waterfall-sets/{id}`   | 查看单个集                                       |
| POST   | `/api/waterfall-sets`        | 创建集(需 `lcoy-waterfall.upload`)              |
| PATCH  | `/api/waterfall-sets/{id}`   | 更新 `title` / `tags`(作者或版主)               |
| DELETE | `/api/waterfall-sets/{id}`   | 删除(作者或版主)                                |

### `waterfall-images`

| 方法   | 路由                              | 说明                                            |
| ------ | --------------------------------- | ----------------------------------------------- |
| GET    | `/api/waterfall-images`           | 列出图片                                         |
| GET    | `/api/waterfall-images/{id}`      | 查看单张图片                                     |
| POST   | `/api/waterfall-images`           | 上传(multipart,需 `lcoy-waterfall.upload`)      |
| DELETE | `/api/waterfall-images/{id}`      | 删除(作者或版主)                                |
| POST   | `/api/waterfall-images/{id}/like` | 点赞(幂等,需 `lcoy-waterfall.like`)             |
| DELETE | `/api/waterfall-images/{id}/like` | 取消点赞(幂等)                                  |
| POST   | `/api/waterfall-images/{id}/view` | 记录浏览(每 IP 每图每分钟 1 次)                 |
| POST   | `/api/waterfall-images/views`     | 批量记录浏览(计数规则相同,一次请求一批)         |

### 其他接口

| 方法 | 路由                        | 说明                                |
| ---- | --------------------------- | ----------------------------------- |
| GET  | `/api/waterfall-upload-logs`| 转存日志,最近 100 条(仅管理员)      |
| POST | `/api/waterfall/test-host`  | 图床连通性检测(仅管理员)            |

### 过滤与排序

- 集:`filter[id]`、`filter[user]`;排序 `-createdAt`、`-score`、`-likesCount`
- 图片:`filter[id]`、`filter[user]`、`filter[set]`;排序 `-createdAt`、
  `-score`、`-likesCount`、`position`

排序名同时接受驼峰(`-createdAt`)与下划线(`-created_at`)两种写法。分页基于
offset:`page[offset]` 与 `page[limit]`(上限 100)。

### 上传示例

```bash
curl -X POST "https://forum.example.com/api/waterfall-images" \
  -H "Authorization: Token YOUR_API_TOKEN" \
  -F "file=@/path/to/image.jpg" \
  -F "title=我的猫" \
  -F "set_id=12" \
  -F "position=0"
```

返回 `201`,图片状态为 `pending`。`set_id` 必须属于上传者(或上传者为版主);不带
`set_id` 的图片允许上传,但不会出现在瀑布流中。

## 存储与队列

- `storage/waterfall-tmp` —— 等待转存的暂存目录,转存完成后立即删除文件。
- `storage/waterfall-relay` —— 仅在启用 `local_relay` 时创建,保留暂存原件以便审计。

任务运行在 Flarum 的**默认队列**上,现有的 `php flarum queue:work` 即可处理,
无需额外 worker。队列驱动(`sync`、`database`、`redis`、`file` 等)取决于站点配置。
全站每分钟配额耗尽时,任务会被放回队列延迟到下一分钟执行,并记为 `deferred`,
**不会丢弃**。

上传日志超过 3 天的记录会被顺带清理。

## 开发

```bash
cd js
yarn install
yarn build          # 生产构建 → js/dist/forum.js、js/dist/admin.js
yarn dev            # 开发构建并监听
yarn check-typings  # tsc --noEmit
yarn format-check   # prettier --check src
yarn test           # Jest 单元测试
```

webpack 入口为 `js/forum.ts` 与 `js/admin.ts`,它们再导出 `js/src/common`、
`js/src/forum` 与 `js/src/admin`。

PHP 集成测试:

```bash
composer test:setup   # 仅需执行一次:创建测试数据库
composer test         # 运行集成测试
```

## 常见问题

**图片一直停留在 `pending`**
若队列驱动不是 `sync`,请确认 worker 正在运行(`php flarum queue:work`)。
前台轮询超过 5 分钟会把该集在本地标记为失败;刷新页面会与服务端重新对齐。

**上传报"文件类型不被允许"**
嗅探到的 MIME 不在 `mime_whitelist` 中。校验依据魔数,与文件扩展名无关。WebP 与
HEIC 是最常被忽略的情况。

**图片失败并带有以下错误码(见上传日志)**
`image_host_not_configured`(上传地址为空或非法)、`image_host_http_error`(非 200)、
`image_host_invalid_response`(响应不是 JSON)、`image_host_missing_src`(缺少 `src`)、
`image_host_unreachable`(3 次尝试后网络/TLS 仍失败)、`staged_file_missing`
(worker 执行前暂存文件已消失)、`unexpected_error`(程序缺陷,请查看 `storage/logs/`)。

**上传被延迟,日志状态为 `deferred`**
触及了每分钟转存配额。提高 `global_per_minute_limit` 或增加队列 worker。

**改了前台相关的设置,但页面仍是旧值**
会被序列化到前台的设置(`per_page`、`card_radius`、`card_gutter`、`show_like_button`、
`slideshow_images`、`poll_interval`)在后台保存时会自动清除 JS 缓存。若直接改数据库,
请执行 `php flarum cache:clear`。

**构建报 "No JS entrypoints could be found"**
`flarum-webpack-config` 需要 `js/forum.ts` 与 `js/admin.ts` 存在。

## 许可

MIT,详见 [LICENSE](LICENSE)。
