/**
 * Tag handling shared by the upload form.
 *
 * The limits mirror WaterfallSetResource on the server, which re-applies them
 * authoritatively — these only shape what the input lets the user write.
 */
export const MAX_TAGS = 5;
export const MAX_TAG_LENGTH = 20;

/**
 * Split a raw input value into tag chips.
 *
 * A comma separates tags, so a pasted "风景,插画,测试" becomes three tags rather
 * than one over-long one. The text after the last comma is the tag still being
 * typed and stays in the field, unless `commitTail` says the input is finished
 * — pressing Enter, leaving the field, or pasting a completed list.
 *
 * Each tag is trimmed and capped to MAX_TAG_LENGTH; duplicates are dropped
 * case-insensitively ("Beach" and "beach" read as one tag) and at most
 * MAX_TAGS tags are kept in total.
 */
export function splitTagDraft(raw: string, existing: readonly string[], commitTail: boolean): { tags: string[]; draft: string } {
  const segments = raw.split(',');
  const tail = segments.pop() ?? '';

  // The tail is a candidate only when the input is finished.
  const candidates = commitTail ? [...segments, tail] : segments;

  const tags = [...existing];

  for (const candidate of candidates) {
    const tag = candidate.trim().slice(0, MAX_TAG_LENGTH);

    if (tag === '' || tags.length >= MAX_TAGS) {
      continue;
    }

    if (tags.some((taken) => taken.toLowerCase() === tag.toLowerCase())) {
      continue;
    }

    tags.push(tag);
  }

  return { tags, draft: commitTail ? '' : tail };
}
