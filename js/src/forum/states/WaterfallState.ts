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

  /**
   * Set when a load-more attempt failed, and cleared by the next attempt.
   *
   * The sentinel cannot recover on its own: the observer has already fired and
   * the element stayed in the viewport, so it will not fire again until the
   * reader scrolls the sentinel out of the 600px margin and back — with
   * nothing on screen saying so, and a blank area where the sentinel is, which
   * reads as "there is no more". The grid watches this to swap the sentinel
   * for a message and a button.
   */
  loadMoreFailed = false;

  protected offset = 0;
  protected initialLoaded = false;

  protected readonly perPage: number;
  protected readonly pollInterval: number;

  /**
   * The `include` list every feed request shares (initial page, next pages,
   * and the backfill for sets that just finished publishing). Built once: the
   * settings it depends on cannot change without a reload, so rebuilding the
   * string per request only repeated work.
   *
   * `images` is the slideshow window; it is requested only when the slideshow
   * is enabled. The poll no longer asks for any include at all — it reads a
   * status string from a dedicated endpoint and backfills only the sets that
   * actually publish (see backfillPublished).
   */
  protected readonly feedInclude: string;

  protected pollTimer: ReturnType<typeof setTimeout> | null = null;
  protected pollStartedAt = 0;
  protected pollTick = 0;
  protected refreshing = false;
  protected pollFailures = 0;
  protected visibilityHandler: (() => void) | null = null;

  /**
   * The first status check after a card turns pending. Short on purpose: a
   * transfer that finishes in seconds should resolve while the reader is
   * still looking at the card.
   */
  protected static readonly POLL_FIRST_DELAY = 2000;

  /**
   * Ceiling for the backoff. Long enough that a slow transfer costs a handful
   * of requests instead of a steady drumbeat, short enough that a card still
   * resolves while the reader is on the page.
   */
  protected static readonly POLL_MAX_INTERVAL = 12000;

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

    // The slideshow window is worth asking for only when there are at least
    // two images to rotate between.
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
    // Whatever the previous attempt ended in, this one is asking again.
    this.loadMoreFailed = false;

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
          // hasMore stays true (there is more, it just did not arrive), so
          // the failure needs its own marker for the grid to act on.
          this.loadMoreFailed = true;
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
    this.loadMoreFailed = false;
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
        if (document.visibilityState === 'hidden') {
          // Nobody is looking at a card in a background tab, so there is
          // nothing worth keeping current: dropping the timer is better than
          // letting the browser fire throttled checks for minutes on a page
          // that is not on screen at all.
          this.clearPollTimer();

          return;
        }

        if (this.pendingSets().length === 0) {
          return;
        }

        // A fresh check the moment the reader comes back, and a re-armed
        // chain in case the pause left none — the timer callback is what
        // normally re-arms it, and that callback was cleared above.
        this.pollTick = 0;
        this.refreshPending();

        if (this.pollTimer === null && this.pendingSets().length > 0) {
          this.schedulePoll();
        }
      };
      document.addEventListener('visibilitychange', this.visibilityHandler);
    }
  }

  /**
   * How long this tick waits before checking: 2s for the first one, then
   * growing geometrically (×1.6) until it reaches the 12s ceiling — unless the
   * admin configured something slower than that cap, in which case their
   * setting wins: a cap is there to stop the backoff from growing without
   * bound, not to interrogate a server more often than it was told to.
   */
  protected pollDelay(): number {
    const cap = Math.max(WaterfallState.POLL_MAX_INTERVAL, this.pollInterval);

    if (this.pollTick === 0) {
      return Math.min(WaterfallState.POLL_FIRST_DELAY, cap);
    }

    // Rounded: the geometric term accumulates binary fractions (5.12s is
    // 5.120000000000001), and a timer wants whole milliseconds.
    return Math.round(Math.min(WaterfallState.POLL_FIRST_DELAY * 1.6 ** this.pollTick, cap));
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

  /**
   * Drop the armed check without ending the polling session: the visibility
   * handler uses this to pause, and `stopPolling` to finish.
   */
  protected clearPollTimer(): void {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  }

  stopPolling(): void {
    this.clearPollTimer();

    if (this.visibilityHandler) {
      document.removeEventListener('visibilitychange', this.visibilityHandler);
      this.visibilityHandler = null;
    }
  }

  /**
   * Ask the server which of the pending sets are still transferring.
   *
   * The answer is a map of ids to status strings from a dedicated endpoint —
   * no document, no includes, one column. The models are updated from that map
   * rather than replaced by a store response, because there is nothing else in
   * the response to replace them with; a set that publishes then gets one
   * ordinary feed request for its cover (see backfillPublished), so the
   * includes are paid for once, when they are useful, instead of on every poll.
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

    // The call comes first and the flag after it, because the request can
    // throw synchronously: a flag left set by that would mute every later poll
    // — and the visibility handler with it — for the rest of the page's life,
    // leaving the cards stuck on "processing" with nothing left to resolve
    // them.
    const request = app.request<Record<string, string>>({
      method: 'GET',
      url: `${app.forum.attribute<string>('apiUrl')}/waterfall/set-status?ids=${encodeURIComponent(ids.join(','))}`,
    });

    this.refreshing = true;

    request
      .then((statuses) => {
        const published: WaterfallSet[] = [];

        for (const set of pending) {
          const id = set.id();
          // An id missing from the answer is a set this viewer cannot see any
          // more. Leaving its card alone keeps the failure mode gentle — the
          // poll timeout marks it — rather than inventing a status for it.
          const status = id ? statuses?.[id] : undefined;

          if (typeof status !== 'string') {
            continue;
          }

          set.pushAttributes({ status });

          if (status === 'published') {
            published.push(set);
          }
        }

        this.touch();
        this.pollFailures = 0;
        m.redraw();

        this.backfillPublished(published);
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
   * Load the sets that just stopped being pending.
   *
   * The status answer carries nothing but the status, so this is the one
   * request that turns a finished card into a real one: the cover, the author,
   * and (when the slideshow is on) its images. It runs only for sets that
   * actually published — usually none, at most one per upload — instead of
   * carrying those includes on every poll.
   *
   * Failures are swallowed: the caller runs inside the poll's promise chain,
   * where a throw would be counted as a poll failure and eventually tell the
   * reader the status could not be refreshed — which would be wrong, the poll
   * itself succeeded. The set is reconciled on the next reload.
   */
  protected backfillPublished(sets: WaterfallSet[]): void {
    const ids = sets.map((set) => set.id()).filter((id): id is string => !!id);

    if (ids.length === 0) {
      return;
    }

    try {
      app.store
        .find('waterfall-sets', ids, { include: this.feedInclude })
        .then(() => {
          // The store mutated the models in place; the array identity has to
          // change or the grid would skip the update.
          this.touch();
          m.redraw();
        })
        .catch(() => {});
    } catch {
      // Best effort by contract: the ids are only ever re-fetched, and the
      // next reload does it again.
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
