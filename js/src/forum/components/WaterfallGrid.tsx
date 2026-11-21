import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type WaterfallSet from '../../common/models/WaterfallSet';
import type WaterfallState from '../states/WaterfallState';
import WaterfallCard from './WaterfallCard';
import {
  DEFAULT_RATIO,
  INFO_HEIGHT,
  columnCount,
  columnWidthFor,
  imageHeightFor,
  placeColumns,
  supportsNativeMasonry,
  type Geometry,
  type Metrics,
} from '../../common/masonry';

export interface WaterfallGridAttrs {
  state: WaterfallState;
  onOpen: (set: WaterfallSet) => void;
}

/** Read once per page: the admin setting cannot change without a reload. */
let cardGutter: number | null = null;

function cardGutterSetting(): number {
  return (cardGutter ??= app.forum.attribute<number>('waterfallCardGutter') ?? 16);
}

/**
 * Whether the engine balances the columns itself. Probed once for the page:
 * the answer cannot change at runtime.
 */
const nativeMasonry = supportsNativeMasonry();

/** Track floor, mirroring `--waterfall-card-min` in the stylesheet. */
const MIN_COLUMN_WIDTH = 280;

/**
 * Above this many cards the grid mounts only what the viewport can reach.
 *
 * A feed stops being cheap somewhere around here: every card is a component
 * with its own observers, and keeping a hundred of them mounted means every
 * redraw walks a hundred vnodes to paint a dozen visible ones.
 */
const WINDOW_THRESHOLD = 50;

/** Screens kept mounted above and below the viewport while windowing. */
const WINDOW_BUFFER = 1.5;

/** How many upcoming covers the idle prefetch warms. */
const PRELOAD_AHEAD = 6;

/** Downloads kept referenced so the browser cannot cancel them. */
const PREFETCH_HANDLES_KEPT = 6;

/** A set's card ratio: the cover's own, or the default frame. */
function coverRatio(set: WaterfallSet): number {
  return set.cover()?.aspectRatio() ?? DEFAULT_RATIO;
}

/**
 * Responsive feed grid.
 *
 * The layout is chosen in three tiers, best first: the engine's native
 * masonry, absolute placement computed from the ratios the API sends, and the
 * plain grid in the stylesheet. The first two reserve every card's final box
 * before a byte of any picture arrives, so nothing shifts when the images
 * land; the third is the fallback for engines where neither is available.
 *
 * Layout is delegated to CSS grid and the browser's native lazy-loading, so
 * there is no manual packing or virtual scrolling to keep in sync. The next
 * page is fetched by an IntersectionObserver sentinel rendered *after* the
 * grid, which also keeps the "end of feed" marker below the last row instead
 * of absolutely positioned on top of the cards.
 */
export default class WaterfallGrid<CustomAttrs extends WaterfallGridAttrs = WaterfallGridAttrs> extends Component<CustomAttrs> {
  protected observer: IntersectionObserver | null = null;
  protected sentinel: Element | null = null;
  protected resizeObserver: ResizeObserver | null = null;

  /** Column geometry for the current width and card list. */
  protected metrics: Metrics | null = null;
  protected geometry: Geometry | null = null;
  protected measuredSets: WaterfallSet[] | null = null;
  protected measuredWidth = 0;

  /** Indices currently worth mounting, when windowing is active. */
  protected visible = new Set<number>();
  protected windowed = false;

  protected scrollHandler: (() => void) | null = null;

  /** Full-size URLs the idle prefetch has already asked for. */
  protected preloaded = new Set<string>();
  protected prefetchHandles: HTMLImageElement[] = [];

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);
    this.observeSentinel();
    this.observeWidth();
    this.rebuild();
    this.preloadAhead();
  }

  onupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onupdate(vnode);
    this.observeSentinel();
    this.rebuild();
    this.preloadAhead();
  }

  onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onremove(vnode);
    this.observer?.disconnect();
    this.observer = null;
    this.sentinel = null;

    this.resizeObserver?.disconnect();
    this.resizeObserver = null;

    this.detachScroll();

    // Nothing should still be downloading for a feed that is gone.
    this.prefetchHandles = [];
  }

  /**
   * Observe the container's width rather than the window's: the feed's width
   * also changes when a sidebar collapses or the admin's page width setting
   * changes, neither of which resizes the viewport.
   */
  protected observeWidth(): void {
    if (nativeMasonry || this.resizeObserver || typeof ResizeObserver === 'undefined') {
      return;
    }

    const grid = this.gridElement();

    if (!grid) {
      return;
    }

    this.resizeObserver = new ResizeObserver(() => {
      this.rebuild(true);
      m.redraw();
    });

    this.resizeObserver.observe(grid);
  }

  /**
   * Observe the bottom sentinel so the next page loads slightly before the
   * user reaches it. Re-observing only when the element changes keeps the
   * observer alive across ordinary redraws.
   */
  protected observeSentinel(): void {
    const sentinel = this.element?.querySelector('.WaterfallGrid-sentinel') ?? null;

    if (!sentinel) {
      this.observer?.disconnect();
      this.observer = null;
      this.sentinel = null;

      return;
    }

    if (this.sentinel === sentinel) {
      return;
    }

    this.observer?.disconnect();
    this.sentinel = sentinel;

    this.observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          this.attrs.state.loadNext();
        }
      },
      // Start fetching well before the user reaches the bottom.
      { rootMargin: '600px 0px' }
    );

    this.observer.observe(sentinel);
  }

  /**
   * Recompute the column geometry.
   *
   * Pure arithmetic on data the server sent — no measurement of any card — so
   * running it on every render is cheap and, more importantly, cannot disagree
   * with what the browser will lay out. `force` is for a width change, which
   * the cached key cannot see.
   */
  protected rebuild(force = false): void {
    if (nativeMasonry) {
      return;
    }

    const grid = this.gridElement();

    if (!grid) {
      return;
    }

    const width = grid.clientWidth;
    const sets = this.attrs.state.sets;

    if (!width || (!force && width === this.measuredWidth && sets === this.measuredSets)) {
      return;
    }

    const gutter = cardGutterSetting();
    const columns = columnCount(width, MIN_COLUMN_WIDTH, gutter);
    const columnWidth = columnWidthFor(width, columns, gutter);
    const infoHeight = this.infoHeight(grid);

    this.metrics = { columns, columnWidth, gutter, infoHeight };
    this.geometry = placeColumns(
      sets.map((set) => imageHeightFor(columnWidth, coverRatio(set)) + infoHeight),
      columns,
      columnWidth,
      gutter
    );

    this.measuredWidth = width;
    this.measuredSets = sets;

    this.recomputeWindow();
  }

  /** The info strip's height, read from the stylesheet so the two agree. */
  protected infoHeight(grid: HTMLElement): number {
    const value = parseFloat(getComputedStyle(grid).getPropertyValue('--wf-info-height'));

    return isFinite(value) && value > 0 ? value : INFO_HEIGHT;
  }

  protected gridElement(): HTMLElement | null {
    return (this.element?.querySelector('.WaterfallGrid-grid') as HTMLElement | null) ?? null;
  }

  /**
   * Pick the indices worth mounting.
   *
   * Below the threshold everything is mounted; above it only the screens the
   * reader can reach are, with a buffer so a fast scroll does not outrun the
   * render. The container keeps its computed height, so the scrollbar and the
   * sentinel below it never jump.
   */
  protected recomputeWindow(): void {
    const geometry = this.geometry;
    const count = this.attrs.state.sets.length;

    if (!geometry || count <= WINDOW_THRESHOLD) {
      const changed = this.windowed;

      this.windowed = false;
      this.visible.clear();
      this.detachScroll();

      if (changed) {
        m.redraw();
      }

      return;
    }

    this.windowed = true;
    this.attachScroll();

    const top = window.scrollY - window.innerHeight * WINDOW_BUFFER;
    const bottom = window.scrollY + window.innerHeight * (1 + WINDOW_BUFFER);
    const next = new Set<number>();

    geometry.placements.forEach((placement, index) => {
      if (placement.y + placement.height >= top && placement.y <= bottom) {
        next.add(index);
      }
    });

    let changed = next.size !== this.visible.size;

    if (!changed) {
      for (const index of next) {
        if (!this.visible.has(index)) {
          changed = true;

          break;
        }
      }
    }

    if (changed) {
      this.visible = next;
      m.redraw();
    }
  }

  protected attachScroll(): void {
    if (this.scrollHandler) {
      return;
    }

    let scheduled = false;

    this.scrollHandler = () => {
      if (scheduled) {
        return;
      }

      scheduled = true;

      requestAnimationFrame(() => {
        scheduled = false;
        this.recomputeWindow();
      });
    };

    // Passive: the listener never calls preventDefault, so the browser keeps
    // scrolling on the compositor while it notifies us.
    window.addEventListener('scroll', this.scrollHandler, { passive: true });
  }

  protected detachScroll(): void {
    if (!this.scrollHandler) {
      return;
    }

    window.removeEventListener('scroll', this.scrollHandler);
    this.scrollHandler = null;
  }

  protected shouldRender(index: number): boolean {
    return !this.windowed || this.visible.has(index);
  }

  /**
   * Warm the covers of the cards just below the fold.
   *
   * Runs when the main thread is idle and only for a bounded tail: prefetching
   * while the visible covers are still arriving would take bandwidth from the
   * very images the reader is waiting for, so this must never compete with the
   * first screen. A card that is already mounted has its own <img> and is
   * skipped.
   */
  protected preloadAhead(): void {
    const sets = this.attrs.state.sets;
    const tail = sets.slice(Math.max(0, sets.length - PRELOAD_AHEAD));

    const urls = tail.map((set) => set.cover()?.displaySrc()).filter((url): url is string => !!url && !this.preloaded.has(url));

    if (urls.length === 0) {
      return;
    }

    const run = () => {
      for (const url of urls) {
        if (this.preloaded.has(url)) {
          continue;
        }

        this.preloaded.add(url);

        const img = new Image();
        img.decoding = 'async';
        (img as HTMLImageElement & { fetchPriority?: string }).fetchPriority = 'low';
        img.src = url;

        // Kept referenced so the download is not mistaken for an abandoned
        // element and cancelled.
        this.prefetchHandles.push(img);
      }

      if (this.prefetchHandles.length > PREFETCH_HANDLES_KEPT) {
        this.prefetchHandles = this.prefetchHandles.slice(-PREFETCH_HANDLES_KEPT);
      }
    };

    if ('requestIdleCallback' in window) {
      requestIdleCallback(run, { timeout: 2000 });
    } else {
      setTimeout(run, 200);
    }
  }

  view() {
    const { state } = this.attrs;

    if (state.loading) {
      return (
        <div className="WaterfallGrid">
          <div className="WaterfallGrid-state WaterfallGrid-state--loading">
            <LoadingIndicator size="large" />
            <p>{app.translator.trans('lcoy-waterfall.forum.grid.loading')}</p>
          </div>
        </div>
      );
    }

    const sets = state.sets;

    if (sets.length === 0) {
      return (
        <div className="WaterfallGrid">
          <div className="WaterfallGrid-state WaterfallGrid-state--empty">
            <div className="WaterfallGrid-emptyArt" aria-hidden="true">
              <i className="far fa-image" />
              <i className="far fa-images" />
            </div>
            <h3>{app.translator.trans('lcoy-waterfall.forum.grid.empty_title')}</h3>
            <p>{app.translator.trans('lcoy-waterfall.forum.grid.empty_text')}</p>
          </div>
        </div>
      );
    }

    // Absolute placement only once this render's geometry exists; the first
    // pass uses the stylesheet's grid, and oncreate computes before the frame
    // is painted, so the reader never sees the intermediate layout.
    const masonry = !nativeMasonry && this.geometry !== null && this.metrics !== null;

    const gridStyle: Record<string, string> = {
      '--waterfall-gutter': `${cardGutterSetting()}px`,
    };

    if (masonry) {
      gridStyle['--waterfall-cols'] = String(this.metrics!.columns);
      gridStyle.height = `${this.geometry!.height}px`;
    }

    return (
      <div className="WaterfallGrid">
        <div
          className={classList('WaterfallGrid-grid', {
            'WaterfallGrid-grid--native-masonry': nativeMasonry,
            'WaterfallGrid-grid--js-masonry': masonry,
          })}
          style={gridStyle}
        >
          {sets.map((set, index) => {
            if (!this.shouldRender(index)) {
              return null;
            }

            const placement = masonry ? this.geometry!.placements[index] : null;

            return (
              <WaterfallCard
                key={set.id()}
                set={set}
                // The first cover is the page's LCP element. `lazy` holds it
                // back until after layout, which is measurably later than it
                // has to be; only that one is made eager, so nothing else
                // competes with the render-blocking stylesheet for priority.
                eager={index === 0}
                x={placement?.x}
                y={placement?.y}
                onclick={() => this.attrs.onOpen(set)}
                onDelete={(deleted: WaterfallSet) => state.removeSet(deleted)}
              />
            );
          })}
        </div>

        {state.hasMore ? (
          state.loadMoreFailed ? (
            // Not the sentinel: the observer has already fired for it and the
            // element never left the viewport, so it would sit blank until the
            // reader happened to scroll it out of the margin and back. An
            // explicit retry is the only way out, and loadNext() already
            // refuses to run twice over, so the button cannot double-fetch.
            // Dropping the sentinel also disconnects the observer, which is
            // what keeps a re-armed one from firing straight into the failure
            // it just came back from.
            <div className="WaterfallGrid-moreFailed">
              <p>{app.translator.trans('lcoy-waterfall.forum.grid.load_failed')}</p>
              <Button className="Button" onclick={() => state.loadNext()}>
                {app.translator.trans('lcoy-waterfall.forum.grid.retry')}
              </Button>
            </div>
          ) : (
            <div className="WaterfallGrid-sentinel" aria-hidden="true">
              {state.loadingMore && <LoadingIndicator size="small" />}
            </div>
          )
        ) : (
          <div className="WaterfallGrid-end">{app.translator.trans('lcoy-waterfall.forum.grid.end_of_feed')}</div>
        )}
      </div>
    );
  }
}
