import app from 'flarum/forum/app';
import WaterfallState from '../../../src/forum/states/WaterfallState';
import WaterfallSet from '../../../src/common/models/WaterfallSet';

/**
 * The feed's polling is the part of the frontend that runs unattended, so its
 * properties are pinned here: the poll asks without the slideshow include, the
 * sets that publish get theirs backfilled, and the wait between checks grows
 * instead of repeating at a fixed interval.
 *
 * Requests are recorded by hand rather than with a spy library: importing
 * `@jest/globals` drags `@jest/environment`'s `/// <reference types="node" />`
 * into the program, and the `@types/node` that then resolves cannot be parsed
 * by this project's pinned TypeScript.
 */
const makeSet = (id: string, status = 'pending') =>
  new WaterfallSet({
    type: 'waterfall-sets',
    id,
    attributes: { status, title: `Set ${id}`, imagesCount: 0, likesCount: 0, viewsCount: 0, score: 0 },
  });

const attributes: Record<string, unknown> = {
  apiUrl: 'http://localhost/api',
  waterfallPerPage: 24,
  waterfallPollInterval: 5,
  waterfallSlideshowImages: 3,
};

/**
 * Record what the state asks for.
 *
 * The status poll and the feed backfill are different transports now, so both
 * are captured: `polls` is every status request, `calls` every feed request —
 * the includes only the backfill pays for.
 */
function recordRequests(statuses: Record<string, string> = {}) {
  const calls: unknown[][] = [];
  const polls: { url: string }[] = [];

  (app as any).forum = { attribute: (key: string) => attributes[key] };
  (app as any).alerts = { show: () => {} };
  (app as any).translator = { trans: (key: string) => key };

  (app as any).request = (options: { url: string }) => {
    polls.push(options);

    return Promise.resolve(statuses);
  };

  (app as any).store = {
    find: (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);

      return Promise.resolve([]);
    },
  };

  return { calls, polls };
}

/** Let the request's promise chain settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const includes = (calls: unknown[][], index: number) => (calls[index][2] as { include: string }).include;

/**
 * jsdom always reports the tab as visible and offers no setter, so the state's
 * pause-on-hidden path is driven through an own property. It shadows the
 * prototype getter for this file only, which is why the default is "visible".
 */
let visibility: 'visible' | 'hidden' = 'visible';

Object.defineProperty(document, 'visibilityState', {
  configurable: true,
  get: () => visibility,
});

describe('WaterfallState polling', () => {
  it('polls the status endpoint instead of the feed', async () => {
    const { calls, polls } = recordRequests({ '1': 'pending' });
    const state = new WaterfallState();

    state.sets = [makeSet('1')];

    (state as any).refreshPending();
    await settle();

    // One small status request and no feed request: while a set is still
    // transferring there is no cover or slideshow to fetch.
    expect(polls).toHaveLength(1);
    expect(polls[0].url).toContain('/waterfall/set-status?ids=1');
    expect(calls).toHaveLength(0);
  });

  it('applies the answer and backfills what published', async () => {
    const published = makeSet('2');
    const { calls } = recordRequests({ '2': 'published' });
    const state = new WaterfallState();

    state.sets = [published];

    (state as any).refreshPending();
    await settle();

    expect(published.status()).toBe('published');

    // The cover and slideshow are fetched once, now that they exist.
    expect(calls).toHaveLength(1);
    expect(calls[0][1]).toEqual(['2']);
    expect(includes(calls, 0)).toBe('user,coverImage,images');
  });

  it('does not backfill a set that failed', async () => {
    const failed = makeSet('3');
    const { calls } = recordRequests({ '3': 'failed' });
    const state = new WaterfallState();

    state.sets = [failed];

    (state as any).refreshPending();
    await settle();

    expect(failed.status()).toBe('failed');
    // A failed set has no images to ask for.
    expect(calls).toHaveLength(0);
  });

  it('starts fast, then backs off to the ceiling', () => {
    const state = new WaterfallState();

    // The first check comes quickly: a transfer that finished seconds ago
    // should resolve while the reader is still looking at the card.
    expect((state as any).pollDelay()).toBe(2_000);

    // Then geometric — 2s, 3.2s, 5.12s, 8.19s — until the 12s ceiling.
    (state as any).pollTick = 1;
    expect((state as any).pollDelay()).toBe(3_200);

    (state as any).pollTick = 2;
    expect((state as any).pollDelay()).toBe(5_120);

    (state as any).pollTick = 3;
    expect((state as any).pollDelay()).toBe(8_192);

    // Capped rather than growing past it.
    (state as any).pollTick = 4;
    expect((state as any).pollDelay()).toBe(12_000);

    (state as any).pollTick = 12;
    expect((state as any).pollDelay()).toBe(12_000);
  });

  it('does not count a backfill that throws as a poll failure', async () => {
    const { calls } = recordRequests({ '6': 'published' });

    // The poll succeeds, and the backfill that follows it blows up
    // synchronously — a request that never left must not be reported to the
    // reader as "the status could not be refreshed".
    (app as any).store.find = (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);

      throw new Error('the backfill request could not be created');
    };

    const state = new WaterfallState();

    state.sets = [makeSet('6')];

    (state as any).refreshPending();
    await settle();

    expect((state as any).pollFailures).toBe(0);
  });

  it('does not start a poll when nothing is pending', () => {
    recordRequests();

    const state = new WaterfallState();

    state.sets = [makeSet('4', 'published')];

    (state as any).startPollingIfNeeded();

    expect((state as any).pollTimer).toBeNull();
  });

  it('parks the armed check while the tab is hidden, and checks again on return', async () => {
    const { polls } = recordRequests({ '1': 'pending' });
    const state = new WaterfallState();

    state.sets = [makeSet('1')];

    (state as any).startPollingIfNeeded();
    expect((state as any).pollTimer).not.toBeNull();

    // A background tab has nobody reading it, so the countdown is dropped
    // rather than left to fire throttled requests for minutes.
    visibility = 'hidden';
    document.dispatchEvent(new Event('visibilitychange'));
    expect((state as any).pollTimer).toBeNull();

    // Coming back means one check now, and a re-armed chain: the timer
    // callback that normally re-arms it was the one that got dropped.
    visibility = 'visible';
    document.dispatchEvent(new Event('visibilitychange'));
    await settle();

    expect(polls).toHaveLength(1);
    expect((state as any).pollTimer).not.toBeNull();
  });
});

/**
 * Infinite scroll has no way to recover by itself. Its IntersectionObserver has
 * already fired for the sentinel and the element never left the viewport, so it
 * will not fire again until the reader scrolls it out of the margin and back —
 * silently, with a blank strip where the sentinel is. A failed page therefore
 * has to be recorded, so the grid can offer the retry the message promises.
 */
describe('WaterfallState load-more recovery', () => {
  /** A store whose next answer the test decides. */
  function controllableStore() {
    const calls: unknown[][] = [];
    let answer: () => Promise<unknown> = () => Promise.resolve([]);

    (app as any).forum = { attribute: (key: string) => attributes[key] };
    (app as any).alerts = { show: () => {} };
    (app as any).translator = { trans: (key: string) => key };
    (app as any).store = {
      find: (resource: unknown, ids: unknown, options: unknown) => {
        calls.push([resource, ids, options]);

        return answer();
      },
    };

    return {
      calls,
      resolves: () => (answer = () => Promise.resolve([])),
      rejects: () => (answer = () => Promise.reject(new Error('offline'))),
      pending: () => {
        let release: (value: unknown) => void = () => {};

        answer = () => new Promise((resolve) => (release = resolve));

        return () => release([]);
      },
    };
  }

  it('records the failure so the grid can offer a retry', async () => {
    const store = controllableStore();
    const state = new WaterfallState();

    store.rejects();
    state.loadNext();
    await settle();

    expect(state.loadMoreFailed).toBe(true);
    // There is more; it just did not arrive. Marking the feed finished here
    // would be a lie the reader has no way to correct.
    expect(state.hasMore).toBe(true);
    expect(state.loadingMore).toBe(false);
  });

  it('clears the failure and asks again when the retry is taken', async () => {
    const store = controllableStore();
    const state = new WaterfallState();

    store.rejects();
    state.loadNext();
    await settle();

    // What the grid's retry button calls.
    store.resolves();
    state.loadNext();
    await settle();

    expect(store.calls).toHaveLength(2);
    expect(state.loadMoreFailed).toBe(false);
  });

  it('refuses a second attempt while one is still in flight', async () => {
    const store = controllableStore();
    const state = new WaterfallState();

    // Guards the retry button against a double click: a second fetch for the
    // same page would arrive with the same offset and duplicate every card.
    const release = store.pending();

    state.loadNext();
    state.loadNext();

    expect(store.calls).toHaveLength(1);

    release();
    await settle();
  });

  it('clears the failure when the sort tab changes', async () => {
    const store = controllableStore();
    const state = new WaterfallState();

    store.rejects();
    state.loadNext();
    await settle();

    expect(state.loadMoreFailed).toBe(true);

    store.resolves();
    state.setSort('recommended');

    expect(state.loadMoreFailed).toBe(false);
  });
});
