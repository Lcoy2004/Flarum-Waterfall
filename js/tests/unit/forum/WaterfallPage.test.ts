import WaterfallPage from '../../../src/forum/components/WaterfallPage';
import WaterfallImage from '../../../src/common/models/WaterfallImage';

/**
 * `removeImage` is the callback a lightbox runs after a delete comes back, and
 * that answer arrives asynchronously — the reader may have closed the lightbox
 * and opened another set by then. Only the set the image actually belonged to
 * may move, so the method identifies its target from the list instead of from
 * whatever is open at that moment.
 *
 * The component reads its state off `this`, so the test sets the fields
 * directly rather than rendering: nothing here needs a DOM tree, only the
 * bookkeeping.
 */
const image = (id: string, status = 'published'): WaterfallImage =>
  new WaterfallImage({
    type: 'waterfall-images',
    id,
    attributes: {
      src: `/file/${id}.png`,
      thumb: `/file/${id}-thumb.png`,
      title: id,
      status,
      likesCount: 0,
      viewsCount: 0,
      score: 0,
    },
  });

/** A stand-in for the set that is open now, recording what is written to it. */
function openSet(setId: string, imagesCount: number) {
  const written: Record<string, unknown>[] = [];

  return {
    written,
    id: () => setId,
    imagesCount: () => imagesCount,
    pushAttributes: (attributes: Record<string, unknown>) => {
      written.push(attributes);
    },
  };
}

/** A page with just the fields removeImage touches. */
const pageWith = (set: ReturnType<typeof openSet>, images: WaterfallImage[], loadedCount: number) => {
  const page: any = new WaterfallPage();

  page.openSet = set;
  page.openSetImages = images;
  page.openSetLoadedCount = loadedCount;
  page.openSetIndex = 0;
  page.state = { removeSet: () => {} };
  page.lightboxEpoch = 0;

  return page;
};

describe('WaterfallPage.removeImage', () => {
  it('leaves the open set untouched when the deleted image is not in its list', () => {
    // The deletion was sent from a set the reader has since left.
    const set = openSet('50', 5);
    const page = pageWith(set, [image('kept')], 3);

    page.removeImage(image('from-another-set'));

    // Not one of these describes the set that is open now.
    expect(set.written).toEqual([]);
    expect(page.openSetLoadedCount).toBe(3);
    expect(page.openSetImages.map((i: WaterfallImage) => i.id())).toEqual(['kept']);
    expect(page.openSet).toBe(set);
  });

  it('drops the image and lowers the count when it is the open set that lost one', () => {
    const set = openSet('50', 5);
    const page = pageWith(set, [image('gone'), image('kept')], 2);

    page.removeImage(image('gone'));

    expect(page.openSetImages.map((i: WaterfallImage) => i.id())).toEqual(['kept']);
    expect(page.openSetLoadedCount).toBe(1);
    expect(set.written).toEqual([{ imagesCount: 4 }]);
  });

  it('does not lower the count for an image the public count never included', () => {
    // imagesCount is published-only, so removing a failed image must leave it.
    const set = openSet('50', 5);
    const page = pageWith(set, [image('broken', 'failed'), image('kept')], 2);

    page.removeImage(image('broken', 'failed'));

    expect(page.openSetImages.map((i: WaterfallImage) => i.id())).toEqual(['kept']);
    expect(set.written).toEqual([]);
  });

  it('does nothing when the lightbox has been closed', () => {
    const set = openSet('50', 5);
    const page = pageWith(set, [], 0);

    page.openSet = null;
    page.removeImage(image('gone'));

    expect(set.written).toEqual([]);
    expect(page.openSetLoadedCount).toBe(0);
  });
});
