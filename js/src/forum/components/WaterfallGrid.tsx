import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import type WaterfallSet from '../../common/models/WaterfallSet';
import type WaterfallState from '../states/WaterfallState';
import WaterfallCard from './WaterfallCard';

export interface WaterfallGridAttrs {
  state: WaterfallState;
  onOpen: (set: WaterfallSet) => void;
}

/** Read once per page: the admin setting cannot change without a reload. */
let cardGutter: number | null = null;

function cardGutterSetting(): number {
  return (cardGutter ??= app.forum.attribute<number>('waterfallCardGutter') ?? 12);
}

/**
 * Responsive card grid. Every card keeps the same portrait cover frame, so
 * rows line up regardless of the original image ratio, and the column count is
 * left entirely to the stylesheet's fluid tracks (no breakpoints here).
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

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);
    this.observeSentinel();
  }

  onupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onupdate(vnode);
    this.observeSentinel();
  }

  onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onremove(vnode);
    this.observer?.disconnect();
    this.observer = null;
    this.sentinel = null;
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

    return (
      <div className="WaterfallGrid">
        {/* The gutter travels as a CSS variable rather than a `gap` value so
            the stylesheet keeps ownership of the property: an inline `gap`
            could only have been overridden with !important. */}
        <div className="WaterfallGrid-grid" style={{ '--waterfall-gutter': `${cardGutterSetting()}px` } as Record<string, string>}>
          {sets.map((set) => (
            <WaterfallCard
              key={set.id()}
              set={set}
              onclick={() => this.attrs.onOpen(set)}
              onDelete={(deleted: WaterfallSet) => state.removeSet(deleted)}
            />
          ))}
        </div>

        {state.hasMore ? (
          <div className="WaterfallGrid-sentinel" aria-hidden="true">
            {state.loadingMore && <LoadingIndicator size="small" />}
          </div>
        ) : (
          <div className="WaterfallGrid-end">{app.translator.trans('lcoy-waterfall.forum.grid.end_of_feed')}</div>
        )}
      </div>
    );
  }
}
