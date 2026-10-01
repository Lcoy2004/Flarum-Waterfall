/**
 * The `detail` of the first error in a JSON:API error document, falling back
 * to the HTTP status when the body is something else.
 */
export function readErrorDetail(xhr: XMLHttpRequest): string {
  try {
    const payload = JSON.parse(xhr.responseText);
    const detail = payload?.errors?.[0]?.detail || payload?.errors?.[0]?.title;

    return typeof detail === 'string' ? detail : `HTTP ${xhr.status}`;
  } catch {
    return `HTTP ${xhr.status}`;
  }
}

/**
 * Whether the server refused only because this uploader already has as many
 * images in flight as the admin allows.
 *
 * Matched on the error's `source.pointer` — the key RateLimiter throws under
 * for exactly this case — and not on the message, which is translated.
 */
export function isCapacityRejection(responseText: string): boolean {
  try {
    const pointer = JSON.parse(responseText)?.errors?.[0]?.source?.pointer;

    return typeof pointer === 'string' && pointer.endsWith('/upload_capacity');
  } catch {
    return false;
  }
}
