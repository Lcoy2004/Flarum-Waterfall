import app from 'flarum/forum/app';
import Lightbox from '../../../src/forum/components/Lightbox';
import WaterfallImage from '../../../src/common/models/WaterfallImage';

/**
 * Views are counted client-side and sent in batches, so the two properties
 * worth pinning are the ones the batching exists for: nothing goes out per
 * image, and what is buffered leaves in one request containing every id.
 *
 * Requests are recorded by hand rather than with a spy library: importing
 * `@jest/globals` drags `@jest/environment`'s `/// <reference types="node" />`
 * into the program, and the `@types/node` that then resolves cannot be parsed
 * by this project's pinned TypeScript.
 */
const makeImage = (id: string) =>
  new WaterfallImage({
    type: 'waterfall-images',
    id,
    attributes: {
      src: `/file/${id}.png`,
      thumb: `/file/${id}-thumb.png`,
      title: id,
      status: 'published',
      likesCount: 0,
      viewsCount: 0,
      score: 0,
    },
  });

/**
 * A component reads its attributes off the vnode, so the test sets them
 * directly instead of rendering one: nothing here needs a DOM tree, only the
 * view bookkeeping.
 */
const openLightbox = (images: WaterfallImage[], index = 0) => {
  const lightbox = new Lightbox();

  (lightbox as any).attrs = { images, index, onClose: () => {}, onNavigate: () => {} };

  return lightbox;
};

/** Requests the lightbox makes, recorded as the options object it passed. */
function recordRequests(): any[] {
  const calls: any[] = [];

  (app as any).forum = { attribute: (key: string) => (key === 'apiUrl' ? '/api' : undefined) };
  // Inside the forum app the session always carries a token: the beacon path
  // needs it, because a request sent on the way out of the page has no header
  // to put it in and Flarum reads it from the body instead.
  (app as any).session = { csrfToken: 'test-token' };
  (app as any).request = (options: unknown) => {
    calls.push(options);

    return Promise.resolve();
  };

  return calls;
}

describe('Lightbox view reporting', () => {
  beforeEach(() => {
    // Buffered ids live on the class, so a test must not inherit the last
    // one's.
    (Lightbox as any).pendingViews.clear();
  });

  it('sends every viewed image in a single request', () => {
    const calls = recordRequests();
    const lightbox = openLightbox([makeImage('batch-a'), makeImage('batch-b')]);

    (lightbox as any).reportView();

    (lightbox.attrs as any).index = 1;
    (lightbox as any).reportView();

    // Nothing has gone out yet: the ids wait for the flush.
    expect(calls).toHaveLength(0);

    (Lightbox as any).flushViews();

    expect(calls).toHaveLength(1);
    expect(calls[0]).toEqual({
      method: 'POST',
      url: '/api/waterfall-images/views',
      body: { ids: ['batch-a', 'batch-b'] },
    });
  });

  it('reports each image once per page session', () => {
    const calls = recordRequests();
    const lightbox = openLightbox([makeImage('once-a')]);

    (lightbox as any).reportView();
    (lightbox as any).reportView();

    (Lightbox as any).flushViews();

    expect(calls).toHaveLength(1);
    expect(calls[0].body).toEqual({ ids: ['once-a'] });
  });

  it('sends nothing when the viewer was opened without looking at an image', () => {
    const calls = recordRequests();

    openLightbox([]);

    (Lightbox as any).flushViews();

    expect(calls).toHaveLength(0);
  });

  it('flushes what is buffered when the page is left', () => {
    const calls = recordRequests();
    const lightbox = openLightbox([makeImage('hide-a')]);

    (lightbox as any).reportView();

    expect(calls).toHaveLength(0);

    // A page going into the back/forward cache is frozen; this is the last
    // moment a request can still leave. jsdom has no beacon, so this is the
    // fallback path.
    document.dispatchEvent(new Event('pagehide'));

    expect(calls).toHaveLength(1);
    expect(calls[0].body).toEqual({ ids: ['hide-a'] });
  });

  it('sends a beacon rather than a request when the page is being left', () => {
    const calls = recordRequests();
    const beacons: { url: string; body: Blob }[] = [];
    const original = (navigator as any).sendBeacon;

    (navigator as any).sendBeacon = (url: string, body: Blob) => {
      beacons.push({ url, body });

      return true;
    };

    try {
      const lightbox = openLightbox([makeImage('beacon-a')]);

      (lightbox as any).reportView();

      document.dispatchEvent(new Event('pagehide'));

      // An XHR would be aborted with the document, which is the whole reason
      // the beacon exists; the token travels in the body because there is no
      // request left to carry the header.
      expect(calls).toHaveLength(0);
      expect(beacons).toHaveLength(1);
      expect(beacons[0].url).toBe('/api/waterfall-images/views');
      expect(beacons[0].body.type).toBe('application/json');
    } finally {
      (navigator as any).sendBeacon = original;
    }
  });

  it('prefetches across the end of the set, the way navigation wraps', () => {
    const lightbox = openLightbox([makeImage('wrap-a'), makeImage('wrap-b'), makeImage('wrap-c')], 2);

    (lightbox as any).prefetchNeighbours();

    // Index 2 is the last of three: stepping forward wraps to 0, so the warm
    // neighbours are 0 and 1 — not "nothing, there is no index 3".
    const prefetched = (lightbox as any).prefetched as Set<string>;

    expect(prefetched.has('/file/wrap-a.png')).toBe(true);
    expect(prefetched.has('/file/wrap-b.png')).toBe(true);
  });

  it('clears the buffer before sending, so a flush cannot report an id twice', () => {
    const calls = recordRequests();
    const lightbox = openLightbox([makeImage('flush-a'), makeImage('flush-b')]);

    (lightbox as any).reportView();
    (Lightbox as any).flushViews();

    (lightbox.attrs as any).index = 1;
    (lightbox as any).reportView();
    (Lightbox as any).flushViews();

    expect(calls).toHaveLength(2);
    expect(calls[0].body).toEqual({ ids: ['flush-a'] });
    expect(calls[1].body).toEqual({ ids: ['flush-b'] });
  });
});
