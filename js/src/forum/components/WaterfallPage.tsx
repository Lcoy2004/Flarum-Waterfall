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

  /** Page size for lightbox image pagination (must match the server cap). */
  protected static readonly IMAGES_PAGE = 50;

  oninit(vnode: Mithril.Vnode<CustomAttrs, this>) {
    super.oninit(vnode);

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

    return (
      <div className="WaterfallPage-hero">
        <div className="WaterfallPage-heroText">
          <h2 className="WaterfallPage-title">{app.translator.trans('lcoy-waterfall.forum.page.title')}</h2>

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
        </div>

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
    this.openSet = set;
    this.openSetIndex = 0;
    this.openSetLoading = true;
    this.openSetImages = [];
    this.openSetLoadedCount = 0;

    const loaded = this.setImages(set);
    const complete = loaded.length > 0 && loaded.length === set.imagesCount();

    const initial = complete
      ? Promise.resolve(loaded)
      : app.store.find<WaterfallSet>('waterfall-sets', set.id()!, { include: 'user,coverImage,images' }).then((fetched) => this.setImages(fetched));

    initial
      .then((images) => {
        this.openSetImages = images.slice();
        this.openSetLoadedCount = images.length;
      })
      .catch((error: unknown) => {
        this.openSet = null;

        const alert = (error as { alert?: AlertAttrs | null } | null)?.alert;

        if (alert) {
          app.alerts.show(alert, alert.content);
        } else {
          app.alerts.show({ type: 'error' }, extractText(app.translator.trans('lcoy-waterfall.forum.grid.load_failed')));
        }
      })
      .then(() => {
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
      this.openSet = null;
      this.openSetLoadedCount = 0;

      if (set) {
        this.state.removeSet(set);
      }
    } else if (set) {
      // Keep the card's count in step; the server has already recomputed
      // the authoritative aggregates.
      set.pushAttributes({ imagesCount: Math.max(0, set.imagesCount() - 1) });
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

    if (!set || this.loadingMoreImages || this.openSetImages.length === 0 || this.openSetLoadedCount >= set.imagesCount()) {
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
        // Skip ids already present: a fresh upload can shift positions while
        // the lightbox is open.
        const existing = new Set(this.openSetImages.map((image) => image.id()));
        const fresh = images.filter((image) => !existing.has(image.id()));

        this.openSetImages = this.openSetImages.concat(fresh);
        this.openSetLoadedCount += images.length;
      })
      .catch(() => {})
      .then(() => {
        this.loadingMoreImages = false;
        m.redraw();
      });
  }
}
