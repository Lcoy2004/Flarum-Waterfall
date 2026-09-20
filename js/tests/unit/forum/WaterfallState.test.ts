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
  waterfallPerPage: 24,
  waterfallPollInterval: 5,
  waterfallSlideshowImages: 3,
};

/** Requests the state makes, recorded as `[resource, ids, options]`. */
function recordRequests(): unknown[][] {
  const calls: unknown[][] = [];

  (app as any).forum = { attribute: (key: string) => attributes[key] };
  (app as any).alerts = { show: () => {} };
  (app as any).store = {
    find: (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);

      return Promise.resolve([]);
    },
  };

  return calls;
}

/** Let the request's promise chain settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const includes = (calls: unknown[][], index: number) => (calls[index][2] as { include: string }).include;

describe('WaterfallState polling', () => {
  it('polls without the slideshow include', async () => {
    const calls = recordRequests();
    const state = new WaterfallState();

    state.sets = [makeSet('1')];

    (state as any).refreshPending();
    await settle();

    expect(calls).toHaveLength(1);
    expect(calls[0][0]).toBe('waterfall-sets');
    expect(calls[0][1]).toEqual(['1']);
    expect(includes(calls, 0)).toBe('user,coverImage');
  });

  it('backfills the slideshow images of a set that published', async () => {
    const published = makeSet('2');
    const calls = recordRequests();

    // The store mutates the model in place when the response arrives — that is
    // how a pending card turns into a published one.
    (app as any).store.find = (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);
      published.pushAttributes({ status: 'published' });

      return Promise.resolve([]);
    };

    const state = new WaterfallState();

    state.sets = [published];

    (state as any).refreshPending();
    await settle();

    expect(calls).toHaveLength(2);
    expect(includes(calls, 0)).toBe('user,coverImage');
    expect(includes(calls, 1)).toBe('user,coverImage,images');
    expect(calls[1][1]).toEqual(['2']);
  });

  it('does not backfill a set that failed', async () => {
    const failed = makeSet('3');
    const calls = recordRequests();

    (app as any).store.find = (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);
      failed.pushAttributes({ status: 'failed' });

      return Promise.resolve([]);
    };

    const state = new WaterfallState();

    state.sets = [failed];

    (state as any).refreshPending();
    await settle();

    // Only the poll: a failed set has no images to ask for.
    expect(calls).toHaveLength(1);
  });

  it('waits longer after every check that found the set still pending', () => {
    const state = new WaterfallState();

    // The configured interval is 5s (see the attributes above).
    expect((state as any).pollDelay()).toBe(5_000);

    (state as any).pollTick = 1;
    expect((state as any).pollDelay()).toBe(10_000);

    (state as any).pollTick = 2;
    expect((state as any).pollDelay()).toBe(20_000);

    // Capped rather than doubling past it.
    (state as any).pollTick = 3;
    expect((state as any).pollDelay()).toBe(30_000);

    (state as any).pollTick = 9;
    expect((state as any).pollDelay()).toBe(30_000);
  });

  it('does not count a backfill that throws as a poll failure', async () => {
    const published = makeSet('6');
    const calls = recordRequests();

    // The poll succeeds, and the backfill that follows it blows up
    // synchronously — a request that never left must not be reported to the
    // reader as "the status could not be refreshed".
    let pollDone = false;

    (app as any).store.find = (resource: unknown, ids: unknown, options: unknown) => {
      calls.push([resource, ids, options]);

      if (!pollDone) {
        pollDone = true;
        published.pushAttributes({ status: 'published' });

        return Promise.resolve([]);
      }

      throw new Error('the backfill request could not be created');
    };

    const state = new WaterfallState();

    state.sets = [published];

    (state as any).refreshPending();
    await settle();

    expect(calls).toHaveLength(2);
    expect((state as any).pollFailures).toBe(0);
  });

  it('does not start a poll when nothing is pending', () => {
    recordRequests();

    const state = new WaterfallState();

    state.sets = [makeSet('4', 'published')];

    (state as any).startPollingIfNeeded();

    expect((state as any).pollTimer).toBeNull();
  });
});
