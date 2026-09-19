/**
 * Map the admin's upload whitelist — the comma-separated extension list stored
 * in lcoy-waterfall.mime_whitelist — to the value of a file input's `accept`
 * attribute, so the picker greys out files the server would reject anyway.
 *
 * "jpg" and "jpeg" share the canonical image/jpeg type, duplicates collapse,
 * and an empty whitelist (a hand-cleared setting) falls back to every image
 * type: the server still rejects what is not whitelisted, but the picker
 * itself stays usable.
 */
export function acceptListForWhitelist(raw: string): string {
  const mimes = new Set<string>();

  for (const ext of raw.split(',')) {
    const normalized = ext.trim().toLowerCase();

    if (normalized === 'jpg' || normalized === 'jpeg') {
      mimes.add('image/jpeg');
    } else if (normalized !== '') {
      mimes.add(`image/${normalized}`);
    }
  }

  return mimes.size > 0 ? [...mimes].join(',') : 'image/*';
}
