import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import type { AlertAttrs } from 'flarum/common/components/Alert';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import type WaterfallImage from '../../common/models/WaterfallImage';
import type WaterfallSet from '../../common/models/WaterfallSet';
import WaterfallState, { type WaterfallSort } from '../states/WaterfallState';
import WaterfallGrid from './WaterfallGrid';
import Lightbox from './Lightbox';

export interface IWaterfallPageAttrs extends IPageAttrs {}

/**
 * The /waterfall forum page: sort tabs, masonry grid with infinite scroll,
 * floating upload button and the lightbox overlay.
 */
export default class WaterfallPage<CustomAttrs extends IWaterfallPageAttrs = IWaterfallPageAttrs> extends Page<CustomAttrs, WaterfallState> {
  // The set currently open in the lightbox, and the index of the visible image
  // within that set.
  protected openSet: WaterfallSet | null = null;
  protected openSetIndex = 0;
  protected openSetLoading = false;

  // The lightbox's image list is paginated: the set's `images` include only
  // carries the first MAX_IMAGES_INCLUDE images, further pages are appended
  // through filter[set]&sort=position as the user navigates.
  protected openSetImages: WaterfallImage[] = [];
  protected openSetLoadedCount = 0;
  protected loadingMoreImages = false;

  // Set once a page shorter than IMAGES_PAGE came back: page size is the
  // server's, so a short page is the set's last one. This is the honest end
  // marker because, unlike imagesCount, it is derived from what the endpoint
  // actually delivered to this visitor.
  protected openSetExhausted = false;

  // Bumped by every open/close of the lightbox; async responses carry the
  // epoch they were started under and are dropped once a newer one exists.
  // Without it, opening set B while set A's request is still in flight lets
  // A's slower response write A's images into B's lightbox, and A's failure
  // close the lightbox B just opened.
  protected lightboxEpoch = 0;

  /** Page size for lightbox image pagination (must match the server cap). */
  protected static readonly IMAGES_PAGE = 50;

  oninit(vnode: Mithril.Vnode<CustomAttrs, this>) {
    super.oninit(vnode);

    // The page reuses IndexSidebar, which flarum-tags populates with every
    // forum tag. In the vertical layout that sidebar renders as a horizontally
    // scrollable strip above the grid, and a long tag list there is noise on a
    // page that isn't about tags — the nav keeps its "All Discussions" and
    // "Tags" links either way. This is the same opt-out flarum's own Messages
    // page and Tags page use.
    app.current.set('noTagsList', true);

    app.history.push('waterfall', extractText(app.translator.trans('lcoy-waterfall.forum.page.back_to_waterfall_tooltip')));

    this.state = new WaterfallState();
    this.state.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);

    app.setTitle(extractText(app.translator.trans('lcoy-waterfall.forum.page.meta_title_text')));
    app.setTitleCount(0);
  }

  onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onremove(vnode);
    this.state.stopPolling();
  }

  view() {
    return (
      <PageStructure className="WaterfallPage Page--vertical" hero={this.hero.bind(this)} sidebar={this.sidebar.bind(this)}>
        {this.content()}
      </PageStructure>
    );
  }

  hero() {
    const canUpload = app.forum.attribute<boolean | undefined>('waterfallCanUpload');
    const isLogged = !!app.session.user;
    // Admin-editable intro, blank until the admin writes one; trimming means a
    // value of only spaces (an accidental save) counts as blank too, so the
    // element and its margin never render empty.
    const description = (app.forum.attribute<string | undefined>('waterfallDescription') ?? '').trim();

    return (
      // `.container` is what lines the header up: Flarum styles the hero band
      // itself as full-bleed and gives only the page container the capped,
      // centred width and 15px side padding, so without it the title, the
      // tabs and the button sat against the viewport edges while the sidebar
      // and the feed below stayed inset. Core's own Hero wraps its content the
      // same way.
      <div className="container WaterfallPage-hero">
        <div className="WaterfallPage-heroTop">
          <div className="WaterfallPage-tabs" role="tablist" aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.page.title'))}>
            {(['latest', 'recommended'] as WaterfallSort[]).map((sort) => (
              <button
                key={sort}
                type="button"
                role="tab"
                aria-selected={this.state.sort === sort ? 'true' : 'false'}
                className={classList('WaterfallPage-tab', { active: this.state.sort === sort })}
                onclick={() => {
                  this.state.setSort(sort);
                }}
              >
                {app.translator.trans(`lcoy-waterfall.forum.tabs.${sort}`)}
              </button>
            ))}
          </div>

          <h2 className="WaterfallPage-title">{app.translator.trans('lcoy-waterfall.forum.page.title')}</h2>

          {isLogged && (
            <Button
              className="Button Button--primary WaterfallPage-uploadButton"
              icon="fas fa-cloud-upload-alt"
              disabled={!canUpload}
              onclick={() => {
                app.modal.show(() => import('./WaterfallUploadModal'), { waterfallState: this.state });
              }}
            >
              {app.translator.trans('lcoy-waterfall.forum.upload_button')}
            </Button>
          )}
        </div>

        {/* Sits below the whole title row rather than inside its centre cell:
            a long intro then wraps against the container's width instead of
            stretching the grid's middle column and squeezing the tabs. */}
        {!!description && <p className="WaterfallPage-description">{description}</p>}
      </div>
    );
  }

  sidebar() {
    return <IndexSidebar />;
  }

  content() {
    return (
      <div className="WaterfallPage-content">
        <WaterfallGrid state={this.state} onOpen={(set: WaterfallSet) => this.openLightbox(set)} />

        {this.openSet && !this.openSetLoading && this.openSetImages.length > 0 && (
          <Lightbox
            images={this.openSetImages}
            index={this.openSetIndex}
            onNearEnd={() => this.loadMoreImages()}
            onDelete={(image: WaterfallImage) => this.removeImage(image)}
            onClose={() => {
              // Invalidate anything still in flight and drop the paging state
              // the closed lightbox owned.
              this.lightboxEpoch++;
              this.loadingMoreImages = false;
              this.openSetExhausted = false;
              this.openSet = null;
              this.openSetImages = [];
              this.openSetLoadedCount = 0;
              m.redraw();
            }}
            onNavigate={(index: number) => {
              this.openSetIndex = index;
              m.redraw();
            }}
          />
        )}
      </div>
    );
  }

  /**
   * Open a set in the lightbox. The feed now carries the set's first few
   * images (the slideshow window: published-only, bounded by the slideshow
   * setting), which is NOT the lightbox's first page (all statuses, bounded
   * by IMAGES_PAGE) — so the in-memory list is reused only when it already
   * holds every image of the set; otherwise the lightbox fetches its own
   * first page on demand before showing it, and further pages load as the
   * user navigates.
   */
  protected openLightbox(set: WaterfallSet): void {
    // Claim the lightbox for this open; any response still in flight from a
    // previous open/close carries an older epoch and is discarded below.
    const epoch = ++this.lightboxEpoch;

    // `imagesCount` counts the set's *published* images, which is exactly what
    // everyone but the uploader can see and only a lower bound for the
    // uploader — their own pending and failed images are visible to them
    // alone. So the count can vouch for a list being complete only when the
    // viewer is provably not the author: a guest, or a signed-in user whose id
    // differs from the set's owner. Anything else, including a payload that
    // did not carry the author, refetches: that is always safe, whereas
    // trusting the count there would hide the images the uploader just added.
    const viewer = app.session.user;
    const owner = set.user();
    const countVouches = !viewer || (!!owner && owner.id() !== viewer.id());

    const loaded = this.setImages(set);
    const complete = countVouches && loaded.length > 0 && loaded.length === set.imagesCount();

    this.openSet = set;
    this.openSetIndex = 0;
    this.openSetLoading = true;
    this.openSetImages = [];
    this.openSetLoadedCount = 0;
    // A run from the previous set must not lock this set's paging: its final
    // callback is dropped by the epoch check, so nothing else would clear it.
    this.loadingMoreImages = false;
    // An already-complete in-memory list needs no page request at all.
    this.openSetExhausted = complete;

    const initial = complete
      ? Promise.resolve(loaded)
      : app.store.find<WaterfallSet>('waterfall-sets', set.id()!, { include: 'user,coverImage,images' }).then((fetched) => this.setImages(fetched));

    initial
      .then((images) => {
        if (epoch !== this.lightboxEpoch) {
          return;
        }

        this.openSetImages = images.slice();
        this.openSetLoadedCount = images.length;

        // Only a page that came back from the server can prove the set is
        // whole: the in-memory copy may simply predate the images the feed has
        // not seen yet, and believing a stale length would leave the uploader
        // looking at a set that never grows. The include is capped at the same
        // page size the lightbox pages with, so a shorter answer is the whole
        // set — and for the uploader this is the only proof available at all,
        // since the published count says nothing about their own images.
        if (!complete && images.length < WaterfallPage.IMAGES_PAGE) {
          this.openSetExhausted = true;
        }
      })
      .catch((error: unknown) => {
        // A failure of a set the user has already navigated away from must not
        // clear the lightbox the newer open is showing.
        if (epoch !== this.lightboxEpoch) {
          return;
        }

        this.openSet = null;

        const alert = (error as { alert?: AlertAttrs | null } | null)?.alert;

        if (alert) {
          app.alerts.show(alert, alert.content);
        } else {
          app.alerts.show({ type: 'error' }, extractText(app.translator.trans('lcoy-waterfall.forum.grid.load_failed')));
        }
      })
      .then(() => {
        if (epoch !== this.lightboxEpoch) {
          return;
        }

        this.openSetLoading = false;
        m.redraw();
      });
  }

  /**
   * A set's loaded images as a clean array (Model.hasMany returns false when
   * the relationship was never loaded).
   */
  protected setImages(set: WaterfallSet): WaterfallImage[] {
    const images = set.images();

    return Array.isArray(images) ? images.filter((image): image is WaterfallImage => !!image) : [];
  }

  /**
   * An image was deleted through the lightbox (Model.delete already removed
   * it from the store): drop it from the open list, and when that was the
   * set's last image close the lightbox and drop the set from the feed — the
   * server's deleted() hook has already removed the empty set.
   */
  protected removeImage(image: WaterfallImage): void {
    const id = image.id();
    const set = this.openSet;

    this.openSetImages = this.openSetImages.filter((existing) => existing.id() !== id);
    this.openSetLoadedCount = Math.max(0, this.openSetLoadedCount - 1);
    this.openSetIndex = Math.min(this.openSetIndex, Math.max(0, this.openSetImages.length - 1));

    if (this.openSetImages.length === 0) {
      // The lightbox is going away without onClose(), so invalidate the
      // in-flight page requests here too.
      this.lightboxEpoch++;
      this.openSet = null;
      this.openSetLoadedCount = 0;

      if (set) {
        this.state.removeSet(set);
      }
    } else if (set) {
      // Keep the card's count in step; the server has already recomputed the
      // authoritative aggregates. The count is published-only, so deleting a
      // failed or still-processing image must leave it alone — decrementing
      // regardless would show a number the server would not agree with until
      // the feed was reloaded.
      if (image.status() === 'published') {
        set.pushAttributes({ imagesCount: Math.max(0, set.imagesCount() - 1) });
      }
    }

    m.redraw();
  }

  /**
   * Append the next page of the open set's images. The server caps the
   * `images` include at MAX_IMAGES_INCLUDE rows, so beyond that the pages
   * come from the images list endpoint filtered by set, in position order.
   */
  protected loadMoreImages(): void {
    const set = this.openSet;
    // Bind the response to the open that started it: without this, a page
    // fetched for a set the user has since left (or closed) is concatenated
    // onto whatever set is open now.
    const epoch = this.lightboxEpoch;

    // What ends pagination is the short page further down, and nothing else:
    // the count is the set's published images while this endpoint hands back
    // whatever the viewer may see, so for the uploader — whose own pending and
    // failed images are visible to them alone — comparing the two would stop
    // the lightbox early and hide images they had just uploaded. (A guard that
    // could never be satisfied is the other half of that coin: every redraw
    // would ask for another page and the empty answer would leave the count
    // exactly where it started.)
    if (!set || this.loadingMoreImages || this.openSetExhausted || this.openSetImages.length === 0) {
      return;
    }

    this.loadingMoreImages = true;

    app.store
      .find<WaterfallImage[]>('waterfall-images', {
        filter: { set: set.id()! },
        sort: 'position',
        page: { offset: this.openSetLoadedCount, limit: WaterfallPage.IMAGES_PAGE },
        include: 'user,likes',
      })
      .then((images) => {
        if (epoch !== this.lightboxEpoch) {
          return;
        }

        // A page shorter than the limit is the last one — the endpoint caps at
        // IMAGES_PAGE, and it delivers by visibility, so this is the honest
        // end marker for every viewer.
        if (images.length < WaterfallPage.IMAGES_PAGE) {
          this.openSetExhausted = true;
        }

        // Skip ids already present: a fresh upload can shift positions while
        // the lightbox is open.
        const existing = new Set(this.openSetImages.map((image) => image.id()));
        const fresh = images.filter((image) => !existing.has(image.id()));

        this.openSetImages = this.openSetImages.concat(fresh);
        this.openSetLoadedCount += images.length;
      })
      .catch(() => {})
      .then(() => {
        // A superseded response must not clear the in-flight marker of the
        // request that owns the lightbox now; openLightbox()/onClose() reset
        // the marker for the run they start, so it can never stay stuck.
        if (epoch !== this.lightboxEpoch) {
          return;
        }

        this.loadingMoreImages = false;
        m.redraw();
      });
  }
}
