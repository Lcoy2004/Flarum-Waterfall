import { encodeThumbnail, THUMB_MAX_EDGE, THUMB_SKIP_BYTES } from '../../../src/common/imageThumbnail';

/**
 * encodeThumbnail needs a canvas to actually encode, which jsdom does not
 * provide — but every decision that comes *before* the canvas is pure, and
 * those are the ones worth pinning: which files are refused a copy, and which
 * are already small enough to need none.
 */
const fileOf = (type: string, size: number): File => ({ type, size } as File);
const sourceOf = (width: number, height: number): ImageBitmap => ({ width, height } as ImageBitmap);

describe('encodeThumbnail skip rules', () => {
  it('returns null for a GIF, so the card keeps the animation', async () => {
    // Large enough to pass the size rule and big enough to be scaled: only the
    // format can be what refuses it.
    await expect(encodeThumbnail(sourceOf(4000, 3000), fileOf('image/gif', 5_000_000))).resolves.toBeNull();
  });

  it('returns null for a file that is already small enough', async () => {
    await expect(encodeThumbnail(sourceOf(4000, 3000), fileOf('image/png', THUMB_SKIP_BYTES - 1))).resolves.toBeNull();
  });

  it('returns null when the image needs no downscaling', async () => {
    // Both edges within the card limit: scale comes out at 1, so a copy would
    // be a re-encode of the same pixels.
    await expect(encodeThumbnail(sourceOf(THUMB_MAX_EDGE, 600), fileOf('image/jpeg', 5_000_000))).resolves.toBeNull();
    await expect(encodeThumbnail(sourceOf(600, THUMB_MAX_EDGE), fileOf('image/jpeg', 5_000_000))).resolves.toBeNull();
  });
});

describe('imageThumbnail size policy', () => {
  it('exposes the card edge and skip thresholds', () => {
    expect(THUMB_MAX_EDGE).toBe(800);
    expect(THUMB_SKIP_BYTES).toBe(150_000);
  });
});
