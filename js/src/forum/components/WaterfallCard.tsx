import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type { AlertAttrs } from 'flarum/common/components/Alert';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import type WaterfallSet from '../../common/models/WaterfallSet';
import type WaterfallImage from '../../common/models/WaterfallImage';

export interface WaterfallCardAttrs {
  set: WaterfallSet;
  onclick: () => void;
  onDelete: (set: WaterfallSet) => void;
}

/**
 * Values that live for the whole page, read once instead of per card per
 * render: admin settings cannot change without a reload, and `matchMedia` is a
 * synchronous style query. Reading either inside `view()` repeated the same
 * lookup for every card on every redraw.
 */
let reduceMotion: boolean | null = null;
let cardRadius: number | null = null;
let slideshowLimit: number | null = null;

function prefersReducedMotion(): boolean {
  return (reduceMotion ??= window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

function cardRadiusSetting(): number {
  return (cardRadius ??= app.forum.attribute<number>('waterfallCardRadius') ?? 8);
}

function slideshowImageLimit(): number {
  return (slideshowLimit ??= app.forum.attribute<number>('waterfallSlideshowImages') || 0);
}

/**
 * What the ticker needs from a card. A structural type rather than the
 * component class, so the ticker stays independent of the component's generic
 * attributes.
 */
interface SlideshowCard {
  advanceSlide(): boolean;
}

/**
 * One interval drives every slideshow in the feed.
 *
 * Each card used to own a timer and call `m.redraw()` itself, so a screenful
 * of cards meant a screenful of timers firing on the same schedule and
 * scheduling the same frame once per card. Here the cards advance together and
 * the frame is drawn once, and the interval only exists while at least one
 * card is enrolled.
 */
class SlideshowTicker {
  /** Crossfade cadence; slow enough to feel calm, quick enough to be noticed. */
  static readonly INTERVAL = 3000;

  protected static readonly cards = new Set<SlideshowCard>();
  protected static timer: ReturnType<typeof setInterval> | null = null;

  static start(card: SlideshowCard): void {
    this.cards.add(card);
    this.timer ??= setInterval(() => this.tick(), SlideshowTicker.INTERVAL);
  }

  static stop(card: SlideshowCard): void {
    this.cards.delete(card);

    if (this.cards.size === 0 && this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  }

  protected static tick(): void {
    // No point advancing a feed nobody is looking at; the state's
    // visibilitychange listener re-syncs when the tab comes back.
    if (document.hidden) {
      return;
    }

    let advanced = false;

    // A card can leave the set while we iterate (it opts out once its slides
    // are gone); Set.forEach tolerates removal during iteration.
    this.cards.forEach((card) => {
      advanced = card.advanceSlide() || advanced;
    });

    if (advanced) {
      m.redraw();
    }
  }
}

/**
 * One IntersectionObserver per root margin for the whole feed.
 *
 * Each card used to own an observer, so a few pages of infinite scroll meant
 * hundreds of them observing the same thing on the same schedule. Cards in the
 * same visibility group share a single observer, and the group is torn down
 * with its last member.
 */
interface VisibilitySink {
  onVisibilityChange(visible: boolean): void;
}

const visibilityGroups = new Map<string, { observer: IntersectionObserver; sinks: Map<Element, VisibilitySink> }>();

function observeVisibility(element: Element, rootMargin: string, sink: VisibilitySink): void {
  let group = visibilityGroups.get(rootMargin);

  if (!group) {
    const sinks = new Map<Element, VisibilitySink>();

    group = {
      sinks,
      observer: new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            sinks.get(entry.target)?.onVisibilityChange(entry.isIntersecting);
          }
        },
        { rootMargin }
      ),
    };

    visibilityGroups.set(rootMargin, group);
  }

  group.sinks.set(element, sink);
  group.observer.observe(element);
}

function unobserveVisibility(element: Element, rootMargin: string): void {
  const group = visibilityGroups.get(rootMargin);

  if (!group) {
    return;
  }

  group.sinks.delete(element);
  group.observer.unobserve(element);

  if (group.sinks.size === 0) {
    group.observer.disconnect();
    visibilityGroups.delete(rootMargin);
  }
}

/**
 * One feed card per image set (Pixiv-style): the set cover in a portrait 3:4
 * frame, an image-count badge, a delete action over the cover and a footer
 * with the uploader and the aggregated like/view counters. The title can be
 * renamed inline by the owner; freeform tags show as subtle chips below it.
 *
 * When the admin enables the slideshow (waterfallSlideshowImages >= 2) the
 * frame rotates through the set's first images: rotation only runs while
 * the card is on screen, pauses on hover, respects prefers-reduced-motion
 * and staggers its starting slide by set id so the grid does not flip in
 * unison. Likes live in the lightbox (per image); the card only shows the
 * totals.
 */
export default class WaterfallCard<CustomAttrs extends WaterfallCardAttrs = WaterfallCardAttrs> extends Component<CustomAttrs> {
  protected editing = false;
  protected savingTitle = false;
  protected draft = '';

  // Slideshow state: current index, per-image loaded flags and the
  // visibility/pause bookkeeping that decides whether the card belongs in the
  // shared ticker.
  protected slide = 0;
  protected slideStarted = false;
  protected slideLoadedIds = new Set<string>();
  protected inView = false;
  protected hoverPaused = false;
  protected ticking = false;
  protected observedFigure: Element | null = null;

  /**
   * Cards are small; arming slightly before they enter the viewport avoids a
   * visible "start" moment at the edge of the screen. Doubles as the key of
   * the shared observer group (see observeVisibility).
   */
  protected static readonly VISIBILITY_MARGIN = '80px 0px';

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);
    this.observeFigure();
  }

  onupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onupdate(vnode);
    this.observeFigure();

    // Images can arrive after the card entered the viewport (the upload poll
    // backfills the `images` relationship when a pending set publishes), and
    // the IntersectionObserver will not fire again for an element that never
    // left it — so give the ticker another chance on every redraw. Cheap:
    // both calls return at their first guard once the card has settled.
    this.startTimer();
  }

  onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onremove(vnode);
    this.stopTimer();

    if (this.observedFigure) {
      unobserveVisibility(this.observedFigure, WaterfallCard.VISIBILITY_MARGIN);
      this.observedFigure = null;
    }
  }

  /**
   * The images the slideshow rotates through: the set's loaded published
   * images, capped to the admin's slideshow count. Fewer than two (or the
   * slideshow disabled) means no rotation — the card renders the plain cover.
   *
   * Not memoised: the store's hasMany maps the id list on every call, so it
   * hands back a fresh array each time and a reference check could never hit.
   * The work is a filter over at most `slideshowImageLimit()` entries.
   */
  protected slides(): WaterfallImage[] {
    const limit = slideshowImageLimit();

    if (limit < 2) {
      return [];
    }

    // Model.hasMany returns false when the relationship was never loaded
    // (e.g. a freshly created set before its first poll), and it resolves ids
    // through the store — so an image the store no longer holds comes back as
    // undefined and is dropped by the filter below, exactly as in
    // WaterfallPage::setImages.
    const images = this.attrs.set.images();

    if (!Array.isArray(images)) {
      return [];
    }

    // The relation is already scoped to published images server-side, but the
    // same set can also have been loaded through the lightbox, whose `images`
    // include is not status-filtered.
    return images
      .filter((image): image is WaterfallImage => !!image)
      .filter((image) => image.status() === 'published')
      .slice(0, limit);
  }

  /**
   * The frames worth having in the DOM: the visible one plus its two
   * neighbours. The previous frame has to stay mounted or the crossfade would
   * have nothing to fade over (and the skeleton would show through), and the
   * next one so it is already decoded when its turn comes.
   *
   * With the slideshow length configurable up to ten, mounting the whole
   * rotation made every visible card fetch its entire set of images the moment
   * it appeared — pictures that would not be shown for another half minute.
   */
  protected windowSlides(slides: WaterfallImage[]): WaterfallImage[] {
    const count = slides.length;

    if (count <= 3) {
      return slides;
    }

    return [slides[(this.slide + count - 1) % count], slides[this.slide], slides[(this.slide + 1) % count]];
  }

  protected slideKey(image: WaterfallImage): string {
    return String(image.id() ?? image.displaySrc());
  }

  protected isSlideLoaded(image: WaterfallImage | null): boolean {
    return !!image && this.slideLoadedIds.has(this.slideKey(image));
  }

  protected markSlideLoaded(image: WaterfallImage): void {
    const key = this.slideKey(image);

    // A cached image fires onload again whenever it is remounted, and the
    // slides are remounted on every relationship reload; only the first
    // transition actually changes what is drawn.
    if (this.slideLoadedIds.has(key)) {
      return;
    }

    this.slideLoadedIds.add(key);
    m.redraw();
  }

  /**
   * Rotation only makes sense when it is actually visible to someone: the
   * shared IntersectionObserver enrolls the card while it is on screen and
   * drops it when it leaves, and a hover keeps the current image still so it
   * can be studied.
   *
   * The registration is left alone while its element is still in the document
   * — re-querying for it on every redraw was a DOM lookup per card per frame.
   * A redraw that replaces the figure disconnects the old node, which is what
   * re-arms the observation here.
   */
  protected observeFigure(): void {
    if (this.observedFigure?.isConnected) {
      return;
    }

    const figure = this.element?.querySelector('.WaterfallCard-figure') ?? null;

    if (this.observedFigure) {
      unobserveVisibility(this.observedFigure, WaterfallCard.VISIBILITY_MARGIN);
      this.observedFigure = null;
    }

    if (!figure) {
      this.inView = false;
      this.stopTimer();

      return;
    }

    this.observedFigure = figure;
    observeVisibility(figure, WaterfallCard.VISIBILITY_MARGIN, this);
  }

  /**
   * Told by the shared observer whether the cover is on screen. Cards are
   * small, so the group is armed slightly before they enter the viewport.
   *
   * @internal
   */
  onVisibilityChange(visible: boolean): void {
    this.inView = visible;

    if (visible) {
      this.startTimer();
    } else {
      this.stopTimer();
    }
  }

  protected startTimer(): void {
    if (this.ticking || this.hoverPaused || !this.inView) {
      return;
    }

    if (this.slides().length < 2 || prefersReducedMotion()) {
      return;
    }

    this.ticking = true;
    SlideshowTicker.start(this);
  }

  protected stopTimer(): void {
    if (!this.ticking) {
      return;
    }

    this.ticking = false;
    SlideshowTicker.stop(this);
  }

  /**
   * Advance the slideshow one step. Called by the shared ticker; returns
   * whether the frame needs redrawing, so a card that has run out of slides
   * can opt itself out of the tick without forcing a draw.
   *
   * @internal
   */
  advanceSlide(): boolean {
    const count = this.slides().length;

    if (count < 2) {
      this.stopTimer();

      return false;
    }

    this.slide = (this.slide + 1) % count;

    return true;
  }

  view() {
    const { set } = this.attrs;
    const radius = cardRadiusSetting();
    const cover = set.cover();
    const user = set.user();
    const status = set.status();
    const canDelete = set.canDelete();
    const canRename = set.canRename();
    const imagesCount = set.imagesCount();
    const likesCount = set.likesCount();
    const viewsCount = set.viewsCount();
    const title = set.title() || '';
    const tags = set.tags();

    // Slideshow slides and the (id-staggered) initial index; clamped on
    // every render so a shrinking slides array (status changes, feed
    // refresh) can never point out of bounds.
    const slides = this.slides();
    const hasSlides = status === 'published' && slides.length >= 2;

    if (slides.length > 0) {
      if (!this.slideStarted) {
        this.slideStarted = true;
        this.slide = Number(set.id() ?? 0) % slides.length;
      }

      this.slide = this.slide % slides.length;
    }

    const currentImage = hasSlides ? slides[this.slide] : cover;

    return (
      <div
        className={classList('WaterfallCard', `WaterfallCard--${status}`)}
        style={{ borderRadius: `${radius}px` }}
        role="group"
        aria-label={title || extractText(app.translator.trans('lcoy-waterfall.forum.card.view_button'))}
      >
        <button
          type="button"
          className="WaterfallCard-figure"
          aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.card.view_button'))}
          onclick={this.attrs.onclick}
          // Pending sets have nothing to show yet (no image has a src), but
          // failed ones stay openable: the lightbox is where the owner reads
          // why each image failed.
          disabled={status === 'pending'}
          onmouseenter={() => {
            this.hoverPaused = true;
            this.stopTimer();
          }}
          onmouseleave={() => {
            this.hoverPaused = false;
            this.startTimer();
          }}
        >
          {/* Skeleton placeholder with breathing animation until onload. */}
          {!this.isSlideLoaded(currentImage) && (
            <div className="WaterfallCard-skeleton" aria-hidden="true">
              {status === 'pending' && <LoadingIndicator size="small" />}
            </div>
          )}

          {status === 'published' && cover && !hasSlides && (
            <img
              className={classList('WaterfallCard-img', { 'WaterfallCard-img--loaded': this.isSlideLoaded(cover) })}
              src={cover.displaySrc()}
              alt={title}
              loading="lazy"
              decoding="async"
              onload={() => {
                this.markSlideLoaded(cover);
              }}
              onerror={() => {
                // Never leave the skeleton up forever on a broken image URL;
                // reveal the (failed) img so the browser's own state shows.
                this.markSlideLoaded(cover);
              }}
            />
          )}

          {hasSlides &&
            this.windowSlides(slides).map((image) => (
              <img
                key={this.slideKey(image)}
                className={classList('WaterfallCard-img', 'WaterfallCard-img--slide', {
                  'WaterfallCard-img--loaded': this.isSlideLoaded(image),
                  'WaterfallCard-img--active': image === slides[this.slide],
                })}
                src={image.displaySrc()}
                alt={title}
                loading="lazy"
                decoding="async"
                onload={() => {
                  this.markSlideLoaded(image);
                }}
                onerror={() => {
                  this.markSlideLoaded(image);
                }}
              />
            ))}

          {imagesCount > 0 && (
            <span
              className="WaterfallCard-count"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.card.images_count', { count: imagesCount }))}
            >
              <i className="far fa-images" aria-hidden="true" /> {imagesCount}
            </span>
          )}

          {hasSlides && (
            <span className="WaterfallCard-dots" aria-hidden="true">
              {slides.map((_, index) => (
                <span key={index} className={classList('WaterfallCard-dot', { 'WaterfallCard-dot--active': index === this.slide })} />
              ))}
            </span>
          )}

          {status === 'pending' && (
            <span className="WaterfallCard-badge WaterfallCard-badge--pending">
              {app.translator.trans('lcoy-waterfall.forum.card.pending_badge')}
            </span>
          )}

          {status === 'failed' && (
            <span className="WaterfallCard-badge WaterfallCard-badge--failed">
              {app.translator.trans('lcoy-waterfall.forum.card.failed_badge')}
            </span>
          )}
        </button>

        {/* The floating action lives outside the figure button so a tap can
            never fall through to the lightbox, and it gets a generous,
            separated touch target. */}
        {canDelete && (
          <div className="WaterfallCard-actions">
            <button
              type="button"
              className="WaterfallCard-action WaterfallCard-action--delete"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.card.delete_button'))}
              onclick={() => this.deleteSet(set)}
            >
              <i className="fas fa-trash-alt" aria-hidden="true" />
            </button>
          </div>
        )}

        <div className="WaterfallCard-info">
          {this.editing ? this.editTitleView() : this.titleView(title, canRename)}

          {tags.length > 0 && (
            <div className="WaterfallCard-tags">
              {tags.map((tag) => (
                <span className="WaterfallCard-tag" key={tag}>
                  {tag}
                </span>
              ))}
            </div>
          )}

          <div className="WaterfallCard-footer">
            {user && (
              <Link className="WaterfallCard-user" href={app.route.user(user)}>
                {/* Flarum's Avatar has no `size` prop — it is sized through
                    the --size CSS variable (see WaterfallCard-user in
                    forum.less). */}
                <Avatar user={user} />
                <span className="WaterfallCard-username">{user.displayName()}</span>
              </Link>
            )}

            <div className="WaterfallCard-stats">
              {likesCount > 0 && (
                <span className="WaterfallCard-stat">
                  <i className="far fa-heart" aria-hidden="true" /> {likesCount}
                </span>
              )}
              {viewsCount > 0 && (
                <span className="WaterfallCard-stat">
                  <i className="far fa-eye" aria-hidden="true" /> {viewsCount}
                </span>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Read-only title row: the (single-line, ellipsised) title plus the rename
   * affordance for the owner.
   */
  protected titleView(title: string, canRename: boolean | undefined): Mithril.Children {
    return (
      <div className="WaterfallCard-titleRow">
        <div className="WaterfallCard-title" title={title || undefined}>
          {title || <span className="WaterfallCard-titlePlaceholder">{app.translator.trans('lcoy-waterfall.forum.card.untitled')}</span>}
        </div>

        {this.savingTitle ? (
          <span className="WaterfallCard-titleSaving" aria-hidden="true">
            <i className="fas fa-circle-notch fa-spin" />
          </span>
        ) : (
          canRename && (
            <button
              type="button"
              className="WaterfallCard-rename"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.card.rename_button'))}
              onclick={() => {
                this.draft = title;
                this.editing = true;
              }}
            >
              <i className="fas fa-pen" aria-hidden="true" />
            </button>
          )
        )}
      </div>
    );
  }

  /**
   * Inline title editor: Enter commits, Esc cancels, clicking away commits
   * (whitespace is collapsed and trimmed by saveTitle).
   */
  protected editTitleView(): Mithril.Children {
    return (
      <div className="WaterfallCard-titleRow WaterfallCard-titleRow--editing">
        <input
          type="text"
          className="FormControl WaterfallCard-titleInput"
          value={this.draft}
          maxlength={200}
          placeholder={extractText(app.translator.trans('lcoy-waterfall.forum.card.title_placeholder'))}
          oninput={(e: InputEvent) => {
            this.draft = (e.target as HTMLInputElement).value;
          }}
          onkeydown={(e: KeyboardEvent) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              this.saveTitle();
            } else if (e.key === 'Escape') {
              e.preventDefault();
              this.editing = false;
              this.draft = '';
              m.redraw();
            }
          }}
          onblur={() => this.saveTitle()}
          oncreate={(vnode: Mithril.VnodeDOM<CustomAttrs, this>) => {
            const input = vnode.dom as HTMLInputElement;
            input.focus();
            input.select();
          }}
        />
      </div>
    );
  }

  /**
   * Persist the renamed title. Whitespace runs are collapsed and the value is
   * trimmed (matching the server's normalizeTitle); an empty title is sent as
   * null so the card falls back to the "untitled" placeholder.
   */
  protected saveTitle(): void {
    if (!this.editing || this.savingTitle) {
      return;
    }

    this.editing = false;

    const set = this.attrs.set;
    const normalized = this.draft.replace(/\s+/gu, ' ').trim();
    this.draft = '';

    if (normalized === (set.title() || '')) {
      return;
    }

    this.savingTitle = true;

    set
      .save({ title: normalized === '' ? null : normalized })
      .catch((error: { alert?: AlertAttrs | null }) => {
        if (error?.alert) {
          app.alerts.show(error.alert, error.alert.content);
        } else {
          app.alerts.show({ type: 'error' }, extractText(app.translator.trans('lcoy-waterfall.forum.card.rename_failed')));
        }
      })
      .then(() => {
        this.savingTitle = false;
        m.redraw();
      });
  }

  protected deleteSet(set: WaterfallSet): void {
    if (!window.confirm(extractText(app.translator.trans('lcoy-waterfall.forum.card.delete_set_confirmation')))) {
      return;
    }

    // Model.delete() issues the DELETE and unregisters the model from the
    // store; the hand-rolled app.request + app.store.remove pair reproduced
    // exactly that.
    set
      .delete()
      .then(() => {
        this.attrs.onDelete(set);
        m.redraw();
      })
      .catch((error: { alert?: AlertAttrs | null }) => {
        // Surface API failures instead of failing silently with an unhandled
        // rejection.
        if (error?.alert) app.alerts.show(error.alert, error.alert.content);
      });
  }
}
