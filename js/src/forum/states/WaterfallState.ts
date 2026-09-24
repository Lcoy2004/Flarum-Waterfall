import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type { AlertAttrs } from 'flarum/common/components/Alert';

import WaterfallSet from '../../common/models/WaterfallSet';

export type WaterfallSort = 'latest' | 'recommended';

/**
 * State of the waterfall feed: current sort tab, loaded image sets, pagination,
 * and pending-set polling.
 *
 * The feed is a list of `waterfall-sets` (one card per upload); each card
 * aggregates its images' counters and opens the lightbox with the whole set.
 */
export default class WaterfallState {
  sort: WaterfallSort = 'latest';
  sets: WaterfallSet[] = [];
  loading = false;
  loadingMore = false;
  hasMore = true;
  protected offset = 0;
  protected initialLoaded = false;

  protected readonly perPage: number;
  protected readonly pollInterval: number;

  /**
   * The `include` list every feed request shares (initial page, next pages,
   * pending polls). Built once: the settings it depends on cannot change
   * without a reload, so rebuilding the string per request only repeated work.
   *
   * `images` is the slideshow window; it is requested only when the slideshow
   * is enabled. The poll deliberately does not ask for it — it backfills the
   * sets that actually publish instead (see backfillSlideshows).
   */
  protected readonly feedInclude: string;

  /**
   * The `include` the pending poll asks for: the same minus the slideshow
   * images. A set that is still transferring has no published image to return,
   * so asking for them costs the server the (windowed) queries that load the
   * relation, and yields nothing — see refreshPending.
   */
  protected readonly pollInclude: string;

  /** Whether the feed loads the slideshow images at all. */
  protected readonly slideshow: boolean;

  protected pollTimer: ReturnType<typeof setTimeout> | null = null;
  protected pollStartedAt = 0;
  protected pollTick = 0;
  protected refreshing = false;
  protected pollFailures = 0;
  protected visibilityHandler: (() => void) | null = null;

  /**
   * Ceiling for the polling backoff (see schedulePoll). Long enough to make a
   * slow transfer cheap to watch, short enough that a card still resolves
   * while the reader is looking at the feed.
   */
  protected static readonly POLL_MAX_INTERVAL = 30000;

  /**
   * Bumped by every load(). Responses carry the epoch they were started
   * under and are discarded once a newer load has begun: switching the sort
   * tab twice in quick succession must not let the first (slower) response
   * overwrite the feed with the wrong sort's sets, nor append a page fetched
   * under the previous sort.
   */
  protected loadEpoch = 0;

  /** Give up polling after this long to avoid hammering the API forever. */
  protected static readonly POLL_TIMEOUT = 300000;

  constructor() {
    this.perPage = app.forum.attribute<number>('waterfallPerPage') || 24;
    this.pollInterval = Math.max(2, app.forum.attribute<number>('waterfallPollInterval') || 5) * 1000;

    const include = ['user', 'coverImage'];
    const slideshow = (app.forum.attribute<number>('waterfallSlideshowImages') || 0) >= 2;

    this.slideshow = slideshow;
    this.pollInclude = include.join(',');

    if (slideshow) {
      include.push('images');
    }

    this.feedInclude = include.join(',');
  }

  protected requestParams(extra: Record<string, unknown> = {}): Record<string, unknown> {
    return {
      include: this.feedInclude,
      sort: this.sort === 'latest' ? '-created_at' : '-score',
      ...extra,
    };
  }

  /**
   * Initial load. Uses the server-preloaded first page (from the /waterfall
   * content renderer) when the active tab matches its sort.
   */
  load(): void {
    if (!this.initialLoaded && this.sort === 'latest') {
      const preloaded = app.preloadedApiDocument<WaterfallSet[]>();

      if (preloaded) {
        this.sets = preloaded;
        this.hasMore = preloaded.length >= this.perPage;
        this.offset = preloaded.length;
        this.initialLoaded = true;
        this.startPollingIfNeeded();

        return;
      }
    }

    const epoch = ++this.loadEpoch;

    this.loading = true;

    app.store
      .find<WaterfallSet[]>('waterfall-sets', this.requestParams({ page: { offset: 0, limit: this.perPage } }))
      .then((sets) => {
        if (epoch !== this.loadEpoch) {
          return;
        }

        this.sets = sets;
        this.offset = sets.length;
        this.hasMore = sets.length >= this.perPage;
        this.initialLoaded = true;
      })
      .catch((error: unknown) => {
        if (epoch === this.loadEpoch) {
          this.showError(error, 'lcoy-waterfall.forum.grid.load_failed');
        }
      })
      .then(() => {
        // The newest load owns the loading flag; a superseded one must not
        // clear it while its replacement is still in flight.
        if (epoch !== this.loadEpoch) {
          return;
        }

        this.loading = false;
        this.startPollingIfNeeded();
        m.redraw();
      });
  }

  /**
   * Load the next page (infinite scroll).
   */
  loadNext(): void {
    if (this.loadingMore || !this.hasMore || this.loading) {
      return;
    }

    const epoch = this.loadEpoch;

    this.loadingMore = true;

    app.store
      .find<WaterfallSet[]>('waterfall-sets', this.requestParams({ page: { offset: this.offset, limit: this.perPage } }))
      .then((sets) => {
        if (epoch !== this.loadEpoch) {
          return;
        }

        // Merge, skipping sets already loaded (an upload may have shifted
        // offsets or appeared at the top).
        const existing = new Set(this.sets.map((set) => set.id()));
        this.sets = this.sets.concat(sets.filter((set) => !existing.has(set.id())));
        this.offset += sets.length;
        this.hasMore = sets.length >= this.perPage;
      })
      .catch((error: unknown) => {
        if (epoch === this.loadEpoch) {
          this.showError(error, 'lcoy-waterfall.forum.grid.load_failed');
        }
      })
      .then(() => {
        // Unlike the data above, this flag is always released: only loadNext
        // itself ever sets it, so a superseded run must not leave it stuck
        // and freeze infinite scrolling.
        this.loadingMore = false;

        if (epoch === this.loadEpoch) {
          m.redraw();
        }
      });
  }

  /**
   * Switch the sort tab and reload the feed.
   */
  setSort(sort: WaterfallSort): void {
    if (this.sort === sort) {
      return;
    }

    this.sort = sort;
    this.sets = [];
    this.hasMore = true;
    this.offset = 0;
    this.initialLoaded = false;
    this.load();
  }

  /**
   * Sets still awaiting their images' image host transfer.
   */
  protected pendingSets(): WaterfallSet[] {
    return this.sets.filter((set) => set.status() === 'pending');
  }

  /**
   * Replace the array identity so the grid re-renders. The store mutates the
   * models in place, so `sets` would otherwise be the very array the grid has
   * already rendered — and Mithril would skip the update.
   */
  protected touch(): void {
    this.sets = this.sets.slice();
  }

  /**
   * Poll the API for the pending sets until they resolve. The interval is
   * configurable in the admin panel. Polling also stops after a timeout, and a
   * visibilitychange listener catches up when the tab regains focus.
   */
  protected startPollingIfNeeded(): void {
    if (this.pollTimer || this.pendingSets().length === 0) {
      return;
    }

    // A fresh polling session starts with a clean failure count: the counter
    // is what decides when to tell the user the status could not be
    // refreshed, and a session that inherits a spent count would never reach
    // the threshold again.
    this.pollFailures = 0;
    this.pollStartedAt = Date.now();
    this.pollTick = 0;

    this.schedulePoll();

    if (!this.visibilityHandler) {
      this.visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
          // A fresh check the moment the reader comes back, on top of the
          // backoff the timer is holding.
          this.refreshPending();
        }
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
    }
  }

  /**
   * How long this tick waits before checking: the configured interval,
   * doubling with every check that still found the set pending, and never
   * longer than POLL_MAX_INTERVAL — unless the admin configured something
   * slower than that cap, in which case their setting wins: a cap is there to
   * stop the backoff from growing without bound, not to interrogate a server
   * more often than it was told to.
   */
  protected pollDelay(): number {
    const cap = Math.max(WaterfallState.POLL_MAX_INTERVAL, this.pollInterval);

    return Math.min(this.pollInterval * 2 ** this.pollTick, cap);
  }

  /**
   * Arm the next status check, waiting longer each time.
   *
   * The first check comes at the configured interval — a card that finished
   * transferring seconds ago should resolve right away — and every check that
   * still finds the set pending doubles the wait up to POLL_MAX_INTERVAL. A
   * set that takes minutes to transfer used to keep one request every few
   * seconds for the whole timeout window, each one a full API boot on the
   * server; the same window now costs a handful of requests, and the card
   * still updates while the reader is on the page.
   */
  protected schedulePoll(): void {
    const delay = this.pollDelay();

    this.pollTick += 1;

    this.pollTimer = setTimeout(() => {
      if (Date.now() - this.pollStartedAt > WaterfallState.POLL_TIMEOUT) {
        // A no-op when nothing is still pending; either way the poll is over.
        this.failStalePending();
        this.stopPolling();

        return;
      }

      this.refreshPending();

      // refreshPending() stops the poll by itself once nothing is pending —
      // it nulls the timer — so the chain only continues when it did not.
      if (this.pollTimer !== null) {
        this.schedulePoll();
      }
    }, delay);
  }

  stopPolling(): void {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }

    if (this.visibilityHandler) {
      document.removeEventListener('visibilitychange', this.visibilityHandler);
      this.visibilityHandler = null;
    }
  }

  /**
   * Batch-fetch every pending set in one request. The store mutates the
   * existing model instances in place, so a redraw is enough to swap the
   * "processing" card for the finished one.
   */
  protected refreshPending(): void {
    if (this.refreshing) {
      return;
    }

    const pending = this.pendingSets();

    if (pending.length === 0) {
      this.stopPolling();

      return;
    }

    const ids = pending.map((set) => set.id()).filter((id): id is string => !!id);

    if (ids.length === 0) {
      return;
    }

    // The call comes first and the flag after it, because store.find() can
    // throw synchronously: a flag left set by that would mute every later poll
    // — and the visibility handler with it — for the rest of the page's life,
    // leaving the cards stuck on "processing" with nothing left to resolve
    // them.
    //
    // Without the slideshow include: while a set is pending there are no
    // published images to return, so it would only cost the server the
    // queries that load the relation. The sets that actually publish get
    // their images from backfillSlideshows instead — one extra request per
    // upload, rather than that cost on every poll.
    const request = app.store.find<WaterfallSet[]>('waterfall-sets', ids, { include: this.pollInclude });

    this.refreshing = true;

    request
      .then(() => {
        // The store mutated the pending models in place (status and cover).
        this.touch();
        this.pollFailures = 0;
        m.redraw();

        this.backfillSlideshows(pending);
      })
      .catch(() => {
        // Do not swallow poll failures silently: after a few consecutive
        // failures the user is told the status could not be refreshed, rather
        // than watching a "processing" badge that never resolves.
        this.pollFailures += 1;

        if (this.pollFailures === 3) {
          app.alerts.show({ type: 'error' }, extractText(app.translator.trans('lcoy-waterfall.forum.grid.poll_failed')));
        }
      })
      .then(() => {
        this.refreshing = false;
      });
  }

  /**
   * Load the slideshow images of the sets that just stopped being pending.
   *
   * The poll asks without the `images` include (see refreshPending), so a card
   * that publishes during a poll has no images loaded and its slideshow would
   * stay empty until the next full page load. One request for the sets that
   * actually published — usually none, and at most one per upload — is far
   * cheaper than carrying the include on every poll.
   *
   * Failures are swallowed: the caller runs inside the poll's promise chain,
   * where a throw would be counted as a poll failure and eventually tell the
   * reader the status could not be refreshed — which would be wrong, the poll
   * itself succeeded. The images come back on the next poll or reload.
   */
  protected backfillSlideshows(sets: WaterfallSet[]): void {
    if (!this.slideshow) {
      return;
    }

    // `images()` comes back false only while the relation is unloaded. An
    // empty array means the opposite of what it looks like: when the feed
    // carries the include, the server scopes the relationship to published
    // images, so a set that is still transferring arrives with `images: []`.
    // Reading that as "already loaded" leaves the card without its slideshow
    // for the rest of the session, which is precisely what this backfills.
    //
    // Only a published set is worth asking about: one that went pending ->
    // failed has no images to show, and the request could only come back empty.
    const ids = sets
      .filter((set) => {
        if (set.status() !== 'published') {
          return false;
        }

        const images = set.images();

        return images === false || images.length === 0;
      })
      .map((set) => set.id())
      .filter((id): id is string => !!id);

    if (ids.length === 0) {
      return;
    }

    try {
      app.store.find('waterfall-sets', ids, { include: this.feedInclude }).catch(() => {});
    } catch {
      // Best effort by contract: the ids are only ever re-fetched, and the
      // next poll or reload does it again.
    }
  }

  /**
   * Polling gave up while sets were still pending: the queue job may have been
   * lost (worker restarted, retries exhausted without ever reporting a failed
   * status). Mark them failed locally so the user gets a definitive error
   * instead of an endless "processing" badge. Reloading reconciles with the
   * server if the transfer did eventually finish.
   */
  protected failStalePending(): void {
    const stale = this.pendingSets();

    if (stale.length === 0) {
      return;
    }

    const message = extractText(app.translator.trans('lcoy-waterfall.forum.card.timeout_error'));

    stale.forEach((set) => set.pushAttributes({ status: 'failed' }));

    this.touch();

    app.alerts.show({ type: 'error' }, message);
    m.redraw();
  }

  /**
   * Surface an API failure: prefer the server-provided alert (rate limit,
   * permission, validation) and fall back to a generic message.
   */
  protected showError(error: unknown, fallbackKey: string): void {
    const alert = (error as { alert?: AlertAttrs | null } | null)?.alert;

    if (alert) {
      app.alerts.show(alert, alert.content);

      return;
    }

    app.alerts.show({ type: 'error' }, extractText(app.translator.trans(fallbackKey)));
  }

  /**
   * Called after a set is created (before its images are uploaded): prepend it
   * to the feed and start polling so the card resolves when processing ends.
   */
  addUploadedSet(set: WaterfallSet): void {
    // A set the feed already holds is being re-announced — a retry after a
    // partially failed upload, say. It has not moved in the server's list, so
    // neither the prepend nor the offset bump applies: doing either would
    // reorder the card and make the next page start one row too far in.
    if (this.sets.some((existing) => existing.id() === set.id())) {
      this.touch();
    } else {
      this.sets = [set, ...this.sets];
      this.offset += 1;
    }

    // The session's clock is restarted by a new arrival. It is what
    // failStalePending() measures to decide the pending cards were abandoned,
    // and an upload that just started must not inherit the age of one that has
    // been running for minutes — it would be condemned seconds after arriving,
    // with its own transfer still going perfectly well.
    this.pollStartedAt = Date.now();
    this.pollTick = 0;
    this.pollFailures = 0;

    this.startPollingIfNeeded();
    m.redraw();
  }

  /**
   * Drop a set the server no longer has (deleted, or its last image was).
   *
   * `offset` counts everything fetched from the server so far, so it has to
   * shrink with the feed: the rows after the deleted one have moved up by one,
   * and leaving the offset alone would make the next page start one row too
   * far in — silently skipping a set the user never sees.
   */
  removeSet(set: WaterfallSet): void {
    if (!this.sets.some((existing) => existing.id() === set.id())) {
      return;
    }

    this.sets = this.sets.filter((existing) => existing.id() !== set.id());
    this.offset = Math.max(0, this.offset - 1);
  }
}
