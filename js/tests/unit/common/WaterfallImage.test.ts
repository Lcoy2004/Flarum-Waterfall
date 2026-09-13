import app from 'flarum/forum/app';
import WaterfallImage from '../../../src/common/models/WaterfallImage';

/**
 * Minimal component-level placeholder test: verifies the frontend model
 * accessors against a raw JSON:API resource object. Full rendering tests
 * should extend this suite once the project adopts @flarum/testing's
 * frontend harness.
 */
describe('WaterfallImage model', () => {
  const makeImage = (attributes: Record<string, unknown> = {}) =>
    new WaterfallImage({
      type: 'waterfall-images',
      id: '1',
      attributes: {
        src: '/file/a.png',
        thumb: null,
        title: 'A title',
        likesCount: 3,
        viewsCount: 10,
        score: 1.5,
        status: 'published',
        createdAt: '2026-09-06T12:00:00Z',
        canLike: true,
        canDelete: false,
        isLiked: false,
        ...attributes,
      },
    });

  it('exposes typed attribute accessors', () => {
    const image = makeImage();

    expect(image.src()).toBe('/file/a.png');
    expect(image.title()).toBe('A title');
    expect(image.likesCount()).toBe(3);
    expect(image.viewsCount()).toBe(10);
    expect(image.score()).toBe(1.5);
    expect(image.status()).toBe('published');
    expect(image.canLike()).toBe(true);
    expect(image.canDelete()).toBe(false);
    expect(image.isLiked()).toBe(false);
  });

  it('falls back to src when thumb is missing', () => {
    const image = makeImage({ thumb: null });

    expect(image.displaySrc()).toBe('/file/a.png');
  });

  it('prefers the thumb when present', () => {
    const image = makeImage({ thumb: '/file/a-thumb.png' });

    expect(image.displaySrc()).toBe('/file/a-thumb.png');
  });

  it('defaults counters when missing', () => {
    const image = makeImage({ likesCount: undefined, viewsCount: undefined, status: undefined });

    expect(image.likesCount()).toBe(0);
    expect(image.viewsCount()).toBe(0);
    expect(image.status()).toBe('pending');
  });

  it('references the forum app for store hydration', () => {
    // The model relies on the app store singleton; ensure the import is
    // wired (guards against accidental circular-import regressions).
    expect(app).toBeDefined();
    expect(app.store).toBeDefined();
  });
});
