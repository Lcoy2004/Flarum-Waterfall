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
   * is enabled, and the poll needs it too so a freshly uploaded set gets its
   * slideshow images the moment it publishes.
   */
  protected readonly feedInclude: string;

  protected pollTimer: ReturnType<typeof setInterval> | null = null;
  protected pollStartedAt = 0;
  protected refreshing = false;
  protected pollFailures = 0;
  protected visibilityHandler: (() => void) | null = null;

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

    if ((app.forum.attribute<number>('waterfallSlideshowImages') || 0) >= 2) {
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

    this.pollTimer = setInterval(() => {
      if (Date.now() - this.pollStartedAt > WaterfallState.POLL_TIMEOUT) {
        // A no-op when nothing is still pending; either way the poll is over.
        this.failStalePending();
        this.stopPolling();

        return;
      }

      // refreshPending() stops the poll by itself once nothing is pending, so
      // the pending list is filtered in exactly one place per tick instead of
      // once to check and again to fetch.
      this.refreshPending();
    }, this.pollInterval);

    if (!this.visibilityHandler) {
      this.visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
          this.refreshPending();
        }
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
    }
  }

  stopPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
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

    this.refreshing = true;

    app.store
      .find<WaterfallSet[]>('waterfall-sets', ids, { include: this.feedInclude })
      .then(() => {
        // The store mutated the pending models in place (status and cover).
        this.touch();
        this.pollFailures = 0;
        m.redraw();
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
