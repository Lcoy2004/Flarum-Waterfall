import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import type { AlertAttrs } from 'flarum/common/components/Alert';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import type WaterfallImage from '../../common/models/WaterfallImage';
import User from 'flarum/common/models/User';

export interface LightboxAttrs {
  images: WaterfallImage[];
  index: number;
  onClose: () => void;
  onNavigate: (index: number) => void;
  /**
   * Called when the visible image is within a few positions of the end, so
   * the owner can append the next page before the user runs out of images.
   */
  onNearEnd?: () => void;
  /**
   * Called after an image was deleted through the lightbox's delete action,
   * so the owner can drop it from the list (and close up if the set is now
   * empty). The action only renders when this is provided.
   */
  onDelete?: (image: WaterfallImage) => void;
}

/** Read once per page: the admin setting cannot change without a reload. */
let showLikeButton: boolean | null = null;

function likesEnabled(): boolean {
  return (showLikeButton ??= app.forum.attribute<boolean>('waterfallShowLikeButton') !== false);
}

/**
 * Minimal native lightbox (chosen over PhotoSwipe to keep the bundle lean):
 * keyboard navigation, Esc to close, wheel/pinch zoom, drag panning and
 * double-click zoom reset. Focus is trapped on the dialog for accessibility.
 */
export default class Lightbox<CustomAttrs extends LightboxAttrs = LightboxAttrs> extends Component<CustomAttrs> {
  protected scale = 1;
  protected panX = 0;
  protected panY = 0;
  protected pointerStart: { x: number; y: number; panX: number; panY: number } | null = null;
  // Two-pointer pinch tracking.
  protected pinchStart: { distance: number; scale: number } | null = null;
  protected pointers = new Map<number, { x: number; y: number }>();
  protected previouslyFocused: HTMLElement | null = null;
  protected static viewSession = new Set<string>();

  // True while the visible image's bytes are still downloading. Set at every
  // point where the image on screen actually changes (mount, navigate,
  // delete) and cleared by the <img> load/error handlers. The browser keeps
  // painting the outgoing image after its src is swapped, which without a
  // signal looks exactly like navigation doing nothing — so the old image is
  // dimmed and a spinner takes over until the new one is ready.
  protected imgLoading = true;

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);

    document.addEventListener('keydown', this.keydownHandler);
    this.previouslyFocused = document.activeElement as HTMLElement | null;

    this.reportView();

    (this.element as HTMLElement).focus();
  }

  onupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onupdate(vnode);

    // Navigating between images re-renders the same component, so views are
    // reported here rather than only in oncreate.
    this.reportView();
    this.maybeLoadMore();

    // A cached image can finish decoding before the onload handler is
    // attached; the element's complete flag covers that race so the spinner
    // can never get stuck on an image that is already there.
    const img = this.element?.querySelector('img.WaterfallLightbox-img') as HTMLImageElement | null;

    if (this.imgLoading && img?.complete) {
      this.imgLoading = false;
      m.redraw();
    }
  }

  /**
   * The new image finished (or failed) downloading: restore full opacity.
   * Failures clear the state too, so the spinner never outlives the image.
   */
  protected onImgLoad = () => {
    this.imgLoading = false;
    m.redraw();
  };

  onremove(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.onremove(vnode);
    document.removeEventListener('keydown', this.keydownHandler);

    if (this.previouslyFocused && document.contains(this.previouslyFocused)) {
      this.previouslyFocused.focus();
    }
  }

  /**
   * Report a view once per image per page session.
   */
  protected reportView(): void {
    const image = this.current();

    if (!image || image.status() !== 'published' || Lightbox.viewSession.has(image.id()!)) {
      return;
    }

    Lightbox.viewSession.add(image.id()!);
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/waterfall-images/${image.id()}/view`,
      })
      .catch(() => {});
  }

  protected keydownHandler = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      this.attrs.onClose();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      this.navigate(-1);
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      this.navigate(1);
    }
  };

  protected current(): WaterfallImage | undefined {
    return this.attrs.images[this.attrs.index];
  }

  protected navigate(direction: number): void {
    const total = this.attrs.images.length;

    // The list can be emptied by a delete while the component is still
    // mounted (the parent unmounts it, but a keypress in that same tick would
    // otherwise divide by zero and poison the index with NaN).
    if (total === 0) {
      return;
    }

    const next = (this.attrs.index + direction + total) % total;
    this.scale = 1;
    this.panX = 0;
    this.panY = 0;
    // The incoming image is almost never decoded yet, and until it is the
    // browser keeps showing the outgoing one — flag the load so the spinner
    // (and the dimmed old image) make the move visible.
    this.imgLoading = true;
    this.attrs.onNavigate(next);
    this.maybeLoadMore();
  }

  /**
   * Ask for the next page when the user approaches the end of the loaded
   * list (also runs on mount/update so an initial page ending exactly at the
   * cap still lets the user continue).
   */
  protected maybeLoadMore(): void {
    if (!this.attrs.onNearEnd) {
      return;
    }

    if (this.attrs.index >= this.attrs.images.length - 5) {
      this.attrs.onNearEnd();
    }
  }

  protected zoomBy(delta: number): void {
    this.scale = Math.min(5, Math.max(0.5, this.scale + delta));
  }

  protected resetZoom(): void {
    this.scale = 1;
    this.panX = 0;
    this.panY = 0;
  }

  protected onWheel = (e: WheelEvent) => {
    e.preventDefault();
    this.zoomBy(e.deltaY < 0 ? 0.2 : -0.2);
    m.redraw();
  };

  protected onPointerDown = (e: PointerEvent) => {
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (this.pointers.size === 1) {
      this.pointerStart = { x: e.clientX, y: e.clientY, panX: this.panX, panY: this.panY };
    } else if (this.pointers.size === 2) {
      const [a, b] = [...this.pointers.values()];
      this.pinchStart = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: this.scale };
    }
  };

  protected onPointerMove = (e: PointerEvent) => {
    if (!this.pointers.has(e.pointerId)) {
      return;
    }

    this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

    if (this.pointers.size >= 2 && this.pinchStart) {
      // Pinch zoom.
      const [a, b] = [...this.pointers.values()];
      const distance = Math.hypot(a.x - b.x, a.y - b.y);
      this.scale = Math.min(5, Math.max(0.5, (this.pinchStart.scale * distance) / this.pinchStart.distance));
    } else if (this.pointerStart) {
      // Drag pan.
      this.panX = this.pointerStart.panX + (e.clientX - this.pointerStart.x);
      this.panY = this.pointerStart.panY + (e.clientY - this.pointerStart.y);
    }
  };

  protected onPointerUp = (e: PointerEvent) => {
    this.pointers.delete(e.pointerId);

    if (this.pointers.size < 2) {
      this.pinchStart = null;
    }

    if (this.pointers.size === 0) {
      const start = this.pointerStart;
      this.pointerStart = null;

      // Un-zoomed, the drag is a swipe gesture: the image follows the finger
      // through panX while dragging, and a decisively horizontal release
      // moves to the neighbouring image. A cancelled gesture (the browser
      // took over — rare with touch-action:none, but possible) only springs
      // back: its coordinates are whatever the cancel reported, not a
      // deliberate release. Zoomed releases are panning and keep their
      // offset — this branch never runs for them.
      if (this.scale <= 1 && start) {
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;

        if (e.type === 'pointerup' && Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.2) {
          this.navigate(dx < 0 ? 1 : -1);
        } else {
          // Not a swipe: an image that fits the stage never stays displaced —
          // spring back (the img's transform transition animates it), keeping
          // whatever zoom level was set.
          this.panX = 0;
          this.panY = 0;
        }
      }
    }
  };

  protected onDoubleClick = (e: MouseEvent) => {
    e.preventDefault();
    this.scale === 1 ? (this.scale = 2) : this.resetZoom();
    m.redraw();
  };

  /**
   * Toggle the like on the visible image. Likes are per image (the card only
   * shows the set's aggregate), so the lightbox is where they are given.
   */
  protected toggleLike(image: WaterfallImage): void {
    const liked = image.isLiked();

    app
      .request({
        method: liked ? 'DELETE' : 'POST',
        url: `${app.forum.attribute('apiUrl')}/waterfall-images/${image.id()}/like`,
        // Re-include the likes relation so the local store receives the full
        // liker list, not just linkage data.
        params: { include: 'user,likes' },
      })
      .then((payload) => {
        app.store.pushPayload(payload as Parameters<typeof app.store.pushPayload>[0]);
        m.redraw();
      })
      .catch((error: { alert?: AlertAttrs | null }) => {
        if (error?.alert) app.alerts.show(error.alert, error.alert.content);
      });
  }

  /**
   * Delete the visible image (owner or moderator only — `canDelete` comes
   * from the server, which also gates the `error` text). The page is told
   * through onDelete so it can drop the image from the open list.
   */
  protected deleteImage(image: WaterfallImage): void {
    if (!window.confirm(extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.delete_image_confirmation')))) {
      return;
    }

    image
      .delete()
      .then(() => {
        // The image on screen changes without going through navigate(), so the
        // zoom has to be dropped here too — otherwise the replacement image
        // appears already magnified and panned to wherever the deleted one was
        // left.
        this.resetZoom();
        // Same reason as navigate(): whichever image takes this slot still has
        // to be fetched.
        this.imgLoading = true;
        this.attrs.onDelete?.(image);
      })
      .catch((error: { alert?: AlertAttrs | null }) => {
        if (error?.alert) app.alerts.show(error.alert, error.alert.content);
      });
  }

  view() {
    const image = this.current();

    if (!image) {
      return null;
    }

    const user = image.user();
    const createdAt = image.createdAt();
    const isLiked = image.isLiked();
    const likers = (image.likes() || []).filter((liker): liker is User => !!liker).slice(0, 5);
    const transform = `translate3d(${this.panX}px, ${this.panY}px, 0) scale(${this.scale})`;

    return (
      <div
        className="WaterfallLightbox"
        role="dialog"
        aria-modal="true"
        aria-label={image.title() || extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.close'))}
        tabindex="-1"
      >
        <div className="WaterfallLightbox-backdrop" onclick={this.attrs.onClose} />

        <div
          className="WaterfallLightbox-stage"
          onwheel={this.onWheel}
          onpointerdown={this.onPointerDown}
          onpointermove={this.onPointerMove}
          onpointerup={this.onPointerUp}
          onpointercancel={this.onPointerUp}
          ondblclick={this.onDoubleClick}
        >
          {image.src() ? (
            <img
              className={classList('WaterfallLightbox-img', { 'is-loading': this.imgLoading })}
              src={image.src()}
              alt={image.title() || ''}
              draggable={false}
              style={{ transform }}
              onload={this.onImgLoad}
              onerror={this.onImgLoad}
            />
          ) : (
            // A failed (or still-processing) image has no src; show why
            // instead of a broken-image icon. The error text is filtered
            // server-side to the owner and moderators.
            <div className="WaterfallLightbox-failed" role="alert">
              <i className="fas fa-exclamation-triangle" aria-hidden="true" />
              <p>{image.error() || extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.failed_placeholder'))}</p>
            </div>
          )}

          {/* Over the dimmed previous image while the next one downloads, so
              navigation visibly did something instead of looking stuck. */}
          {this.imgLoading && image.src() && (
            <LoadingIndicator className="WaterfallLightbox-loading" containerClassName="WaterfallLightbox-loadingContainer" size="large" />
          )}
        </div>

        <div className="WaterfallLightbox-toolbar">
          <div className="WaterfallLightbox-toolbarMain">
            {image.title() && <span className="WaterfallLightbox-title">{image.title()}</span>}
            <span className="WaterfallLightbox-counter">
              {app.translator.trans('lcoy-waterfall.forum.lightbox.counter', {
                index: this.attrs.index + 1,
                total: this.attrs.images.length,
              })}
            </span>
          </div>

          <div className="WaterfallLightbox-toolbarActions">
            {user && (
              <Link className="WaterfallLightbox-user" href={app.route.user(user)}>
                {/* Avatar is sized via the --size CSS variable, not a prop. */}
                <Avatar user={user} />
                <span>{user.displayName()}</span>
              </Link>
            )}
            {createdAt && <span className="WaterfallLightbox-time">{humanTime(createdAt)}</span>}
            <span className="WaterfallLightbox-views">
              <i className="far fa-eye" aria-hidden="true" /> {image.viewsCount()}
            </span>
            <span className="WaterfallLightbox-likes">
              <i className="far fa-heart" aria-hidden="true" /> {image.likesCount()}
            </span>
            {likers.length > 0 && (
              <span
                className="WaterfallLightbox-likers"
                aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.card.likes_aria', { count: image.likesCount() }))}
              >
                {likers.map((liker) => (
                  <Link key={liker.id()} href={app.route.user(liker)} className="WaterfallLightbox-likerLink">
                    <Avatar user={liker} />
                  </Link>
                ))}
              </span>
            )}
            {likesEnabled() && (
              <Button
                className={classList('Button Button--icon WaterfallLightbox-action', 'WaterfallLightbox-action--like', { 'is-active': isLiked })}
                icon={isLiked ? 'fas fa-heart' : 'far fa-heart'}
                aria-label={extractText(app.translator.trans(`lcoy-waterfall.forum.card.${isLiked ? 'unlike_button' : 'like_button'}`))}
                aria-pressed={isLiked ? 'true' : 'false'}
                disabled={!image.canLike()}
                onclick={() => this.toggleLike(image)}
              />
            )}
            {this.attrs.onDelete && image.canDelete() && (
              <Button
                className="Button Button--icon WaterfallLightbox-action WaterfallLightbox-action--delete"
                icon="fas fa-trash-alt"
                aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.delete_image'))}
                onclick={() => this.deleteImage(image)}
              />
            )}
            <Button
              className="Button Button--icon WaterfallLightbox-action"
              icon="fas fa-search-plus"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.zoom_in'))}
              onclick={() => {
                this.zoomBy(0.5);
                m.redraw();
              }}
            />
            <Button
              className="Button Button--icon WaterfallLightbox-action"
              icon="fas fa-search-minus"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.zoom_out'))}
              onclick={() => {
                this.zoomBy(-0.5);
                m.redraw();
              }}
            />
            <Button
              className="Button Button--icon WaterfallLightbox-action"
              icon="fas fa-times"
              aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.close'))}
              onclick={this.attrs.onClose}
            />
          </div>
        </div>

        <Button
          className="Button Button--icon WaterfallLightbox-nav WaterfallLightbox-nav--prev"
          icon="fas fa-chevron-left"
          aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.previous'))}
          onclick={() => this.navigate(-1)}
        />
        <Button
          className="Button Button--icon WaterfallLightbox-nav WaterfallLightbox-nav--next"
          icon="fas fa-chevron-right"
          aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.lightbox.next'))}
          onclick={() => this.navigate(1)}
        />

        <div className="WaterfallLightbox-hint">{app.translator.trans('lcoy-waterfall.forum.lightbox.zoom_hint')}</div>
      </div>
    );
  }
}
