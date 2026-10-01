/**
 * Browser-side card thumbnail encoding.
 *
 * The feed renders `thumb ?? src`, so a card-sized copy keeps every grid visit
 * from downloading the full-size original (a phone photo is megabytes; the
 * copy is tens of kilobytes). Files this small gain nothing from one.
 */
export const THUMB_MAX_EDGE = 800;
export const THUMB_SKIP_BYTES = 150_000;

/**
 * Decode with EXIF orientation applied. `from-image` is what makes
 * createImageBitmap respect it; the <img> fallback inherits the same
 * behaviour from the CSS default `image-orientation: from-image`.
 */
export async function decodeImage(file: File): Promise<ImageBitmap | HTMLImageElement> {
  if ('createImageBitmap' in window) {
    try {
      const options: ImageBitmapOptions = {};

      // 'from-image' postdates this TypeScript's lib.dom (whose union is
      // only "flipY" | "none"); every current engine accepts it, so poke
      // the value through without weakening the rest of the type.
      (options as { imageOrientation?: string }).imageOrientation = 'from-image';

      return await createImageBitmap(file, options);
    } catch {
      // Engines that reject the options bag (or exotic files) fall through
      // to the <img> path below.
    }
  }

  const url = URL.createObjectURL(file);

  try {
    const img = new Image();

    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error('decode failed'));
      img.src = url;
    });

    return img;
  } finally {
    URL.revokeObjectURL(url);
  }
}

/**
 * Encode the card-sized copy. GIFs are skipped (a static frame would kill
 * the animation on the card), as are files with nothing to shrink. WebP is
 * asked for first and JPEG kept as the fallback; a browser that cannot
 * encode the requested type silently returns a PNG instead, so the blob's
 * actual type is what decides.
 */
export async function encodeThumbnail(source: ImageBitmap | HTMLImageElement, file: File): Promise<Blob | null> {
  if (file.type === 'image/gif' || file.size < THUMB_SKIP_BYTES) {
    return null;
  }

  const scale = Math.min(1, THUMB_MAX_EDGE / Math.max(source.width, source.height));

  if (scale >= 1) {
    return null;
  }

  const canvas = document.createElement('canvas');
  canvas.width = Math.round(source.width * scale);
  canvas.height = Math.round(source.height * scale);

  const context = canvas.getContext('2d');

  if (!context) {
    return null;
  }

  // JPEG has no alpha channel, so a transparent source would come out on a
  // black background in the fallback encoder. White matches what the card
  // shows for a transparent image in a browser that *can* encode WebP.
  context.fillStyle = '#fff';
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.drawImage(source, 0, 0, canvas.width, canvas.height);

  for (const type of ['image/webp', 'image/jpeg'] as const) {
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, 0.8));

    if (blob && blob.type === type) {
      return blob;
    }
  }

  return null;
}
