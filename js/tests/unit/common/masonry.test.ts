import { columnCount, columnWidthFor, imageHeightFor, placeColumns, DEFAULT_RATIO } from '../../../src/common/masonry';

/**
 * The feed reserves every card's box from geometry alone — no image is ever
 * measured — so these few functions decide whether the columns balance and
 * whether anything shifts when the pictures arrive. They are pure, which is
 * exactly why they are worth pinning.
 */
describe('masonry geometry', () => {
  it('fits as many columns as the width allows, never fewer than one', () => {
    expect(columnCount(0, 280, 12)).toBe(1);
    // 375px: one comfortable card, not two cramped ones.
    expect(columnCount(375, 280, 12)).toBe(1);
    // 900px: three (3 * 280 + 2 * 12 = 864).
    expect(columnCount(900, 280, 12)).toBe(3);
  });

  it('divides the remaining width evenly between the columns', () => {
    // (900 - 2 * 12) / 3 = 292
    expect(columnWidthFor(900, 3, 12)).toBe(292);
  });

  it('turns a width and a ratio into the height the picture occupies', () => {
    // width / ratio, because the ratio is width divided by height.
    expect(imageHeightFor(300, 0.75)).toBe(400);
    expect(imageHeightFor(300, 1.5)).toBe(200);
  });

  it('falls back to the default frame when the ratio is missing or nonsense', () => {
    for (const ratio of [null, undefined, 0, -1, Number.NaN, Number.POSITIVE_INFINITY]) {
      expect(imageHeightFor(300, ratio)).toBe(300 / DEFAULT_RATIO);
    }
  });

  it('places each card in the shortest column and reports the tallest column', () => {
    // Three columns. Heights are chosen so the second card has to go into a
    // different column than the first, and the fourth into the shortest one.
    const geometry = placeColumns([400, 200, 200, 100], 3, 100, 10);

    expect(geometry.placements).toEqual([
      { x: 0, y: 0, width: 100, height: 400 },
      { x: 110, y: 0, width: 100, height: 200 },
      { x: 220, y: 0, width: 100, height: 200 },
      // Shortest column is #2 or #3 (both 200 tall); the first of them wins.
      { x: 110, y: 210, width: 100, height: 100 },
    ]);

    // Tallest column is the first: 400. The trailing gutter is not content.
    expect(geometry.height).toBe(400);
  });

  it('keeps source order inside each column', () => {
    const geometry = placeColumns([100, 100, 100, 100], 2, 100, 10);

    // Cards 0 and 2 share the first column, 1 and 3 the second; each later
    // card sits below the one before it in the same column.
    expect(geometry.placements[2].y).toBeGreaterThan(geometry.placements[0].y);
    expect(geometry.placements[3].y).toBeGreaterThan(geometry.placements[1].y);
  });
});
