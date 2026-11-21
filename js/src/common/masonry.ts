/**
 * Geometry for the feed's variable-height layout.
 *
 * The whole point of this module is that nothing here measures the DOM. A
 * card's height is derived from data the server already sends — its column
 * width and the image's own ratio — so the first paint is also the final one:
 * the browser never has to reflow when the picture arrives, which is what
 * keeps CLS at zero while the cards still have different heights.
 *
 * Three tiers, best first (WaterfallGrid decides which one is in play):
 *   1. the engine's own masonry (no script involved at all);
 *   2. absolute placement from this module;
 *   3. the plain grid in the stylesheet, when neither is available.
 */

/** A 3:4 portrait, used for rows that predate the ratio column. */
export const DEFAULT_RATIO = 3 / 4;

/** Info strip height, in px. Kept in sync with `--wf-info-height`. */
export const INFO_HEIGHT = 72;

export interface Metrics {
  columns: number;
  columnWidth: number;
  gutter: number;
  infoHeight: number;
}

export interface Placement {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface Geometry {
  placements: Placement[];
  /** Total height of the tallest column, in px. */
  height: number;
}

/**
 * Whether the engine can balance the columns itself via
 * `grid-template-rows: masonry`.
 *
 * Probed at runtime rather than with `@supports` in the stylesheet: support is
 * still uneven, and an unknown value inside a Less-compiled `@supports` rule is
 * reported differently (or not at all) by different engines.
 */
export function supportsNativeMasonry(): boolean {
  try {
    return typeof CSS !== 'undefined' && typeof CSS.supports === 'function' && CSS.supports('grid-template-rows', 'masonry');
  } catch {
    return false;
  }
}

/**
 * How many columns fit. `floor` and not `round`: a fractional track would
 * either overflow or leave a ragged gap, and a slightly wider card is the
 * better trade.
 */
export function columnCount(width: number, minColumnWidth: number, gutter: number): number {
  if (!(width > 0)) {
    return 1;
  }

  const min = Math.max(1, minColumnWidth);

  return Math.max(1, Math.floor((width + gutter) / (min + gutter)));
}

export function columnWidthFor(width: number, columns: number, gutter: number): number {
  if (columns < 1) {
    return width;
  }

  return Math.max(0, (width - (columns - 1) * gutter) / columns);
}

/**
 * The height a picture occupies at the given width. `ratio` is width / height,
 * so height is width divided by it.
 */
export function imageHeightFor(width: number, ratio: number | null | undefined): number {
  const safe = typeof ratio === 'number' && isFinite(ratio) && ratio > 0 ? ratio : DEFAULT_RATIO;

  return width / safe;
}

/**
 * Place each card in the currently shortest column, in order.
 *
 * Shortest-column placement is what makes the columns balance, and doing it in
 * source order is what keeps the reading order the uploader chose: card N is
 * always below card N-1 in its own column.
 */
export function placeColumns(heights: number[], columns: number, columnWidth: number, gutter: number): Geometry {
  const count = Math.max(1, columns);
  const columnHeights = new Array<number>(count).fill(0);
  const placements: Placement[] = [];

  for (const height of heights) {
    let column = 0;

    for (let i = 1; i < count; i++) {
      if (columnHeights[i] < columnHeights[column]) {
        column = i;
      }
    }

    placements.push({
      x: column * (columnWidth + gutter),
      y: columnHeights[column],
      width: columnWidth,
      height,
    });

    columnHeights[column] += height + gutter;
  }

  // The last row's trailing gutter is not part of the content's height.
  const height = Math.max(0, ...columnHeights.map((value) => Math.max(0, value - gutter)));

  return { placements, height };
}
