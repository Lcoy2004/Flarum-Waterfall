import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type { AlertAttrs } from 'flarum/common/components/Alert';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type { IInternalModalAttrs } from 'flarum/common/components/Modal';

import type WaterfallSet from '../../common/models/WaterfallSet';
import { MAX_TAGS, MAX_TAG_LENGTH, splitTagDraft } from '../../common/tags';
import type WaterfallState from '../states/WaterfallState';

type QueueStatus = 'ready' | 'uploading' | 'done' | 'error';

interface QueueItem {
  // Stable identity across re-renders and drag reordering (the array index
  // changes when items are dragged or removed).
  key: string;
  file: File;
  title: string;
  // Intrinsic size, pre-read locally for the queue label only.
  width: number | null;
  height: number | null;
  // Card-sized copy encoded in the browser (see makeThumb): the feed renders
  // `thumb ?? src`, so without one every card would download the full-size
  // original. Null when the file gains nothing from a copy (small, GIF, or
  // the encoder is unavailable).
  thumb: Blob | null;
  progress: number;
  status: QueueStatus;
  error: string | null;
  xhr: XMLHttpRequest | null;
  // Set when the user cancels an in-flight upload: the sequential upload
  // chain must not immediately pick this item up again.
  cancelled: boolean;
}

export interface WaterfallUploadModalAttrs extends IInternalModalAttrs {
  // The modal manager reserves the `state` key for its own ModalManagerState,
  // so the waterfall feed state travels under a dedicated attribute.
  waterfallState: WaterfallState;
}

/**
 * Upload dialog: click-to-choose, drag & drop, multi-file queue, drag-to-reorder
 * of the queue (the order becomes the set's display order), per-file and
 * overall progress (XHR upload events), retry on failure and cancel.
 *
 * One upload session is one image set: the set is created first (with the
 * optional title), then every file is sent with its `set_id` and `position`.
 * The files go to the API endpoint which stages them and answers with a
 * `pending` resource; the queue worker then transfers them to the image host.
 */
export default class WaterfallUploadModal<CustomAttrs extends WaterfallUploadModalAttrs = WaterfallUploadModalAttrs> extends Modal<CustomAttrs> {
  protected queue: QueueItem[] = [];
  protected dragging = false;
  protected submitting = false;

  // Title of the set created on submit (empty = untitled).
  protected setTitle = '';
  // Freeform tags the user writes for the set (chips input). Normalised
  // client-side (trim / length / dedupe) and again server-side.
  protected tags: string[] = [];
  protected tagDraft = '';
  // The set created for this upload session, and whether it has been inserted
  // into the feed yet (done on the first successful file).
  protected set: WaterfallSet | null = null;
  protected setAdded = false;

  // Drag-to-reorder bookkeeping (keys, not indices: indices shift while
  // dragging).
  protected dragKey: string | null = null;
  protected dragOverKey: string | null = null;

  /**
   * In-flight `prepareItem` runs, awaited by submit() so an upload can never
   * start before the queued files have their card copies. They never reject.
   */
  protected preparing: Promise<void>[] = [];

  protected keySeq = 0;

  className() {
    return 'WaterfallUploadModal Modal--large';
  }

  title() {
    return app.translator.trans('lcoy-waterfall.forum.upload_modal.title');
  }

  content() {
    const total = this.queue.length;
    const done = this.queue.filter((item) => item.status === 'done' || item.status === 'error').length;
    const allDone = total > 0 && this.queue.every((item) => item.status === 'done' || item.status === 'error');
    const hasErrors = this.queue.some((item) => item.status === 'error');
    const overall = total > 0 ? Math.round(this.queue.reduce((sum, item) => sum + item.progress, 0) / total) : 0;

    return (
      <div className="Modal-body WaterfallUploadModal-body">
        <p className="WaterfallUploadModal-description">{app.translator.trans('lcoy-waterfall.forum.upload_modal.description')}</p>

        <div className="WaterfallUploadModal-setTitle">
          <label className="WaterfallUploadModal-setTitleLabel" for="waterfall-set-title">
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.set_title_label')}
          </label>
          <input
            id="waterfall-set-title"
            type="text"
            className="FormControl"
            value={this.setTitle}
            maxlength={200}
            disabled={this.submitting}
            placeholder={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.set_title_placeholder'))}
            oninput={(e: InputEvent) => {
              this.setTitle = (e.target as HTMLInputElement).value;
            }}
          />
          <p className="WaterfallUploadModal-setTitleHint">{app.translator.trans('lcoy-waterfall.forum.upload_modal.set_title_hint')}</p>
        </div>

        <div className="WaterfallUploadModal-tags">
          <label className="WaterfallUploadModal-setTitleLabel" for="waterfall-set-tags">
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.tags_label')}
          </label>

          <div className="WaterfallUploadModal-tagsField" onclick={() => this.focusTagInput()}>
            {this.tags.map((tag) => (
              <span className="WaterfallUploadModal-tag" key={tag}>
                {tag}
                <button
                  type="button"
                  className="WaterfallUploadModal-tagRemove"
                  aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.tag_remove', { tag }))}
                  disabled={this.submitting}
                  onclick={() => {
                    this.tags = this.tags.filter((existing) => existing !== tag);
                  }}
                >
                  <i className="fas fa-times" aria-hidden="true" />
                </button>
              </span>
            ))}

            {this.tags.length < MAX_TAGS && (
              <input
                id="waterfall-set-tags"
                type="text"
                className="WaterfallUploadModal-tagInput"
                value={this.tagDraft}
                disabled={this.submitting}
                placeholder={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.tags_placeholder'))}
                oninput={(e: InputEvent) => {
                  const input = e.target as HTMLInputElement;

                  // A comma always separates tags — including the ones that
                  // arrive by paste, which the old keydown-only handler turned
                  // into a single over-long tag. A paste is a finished list, so
                  // its last segment counts too; while typing, whatever follows
                  // the last comma stays in the field.
                  this.commitTagDraft(input.value, e.inputType.startsWith('insertFromPaste'));
                }}
                onkeydown={(e: KeyboardEvent) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    this.commitTagDraft(this.tagDraft, true);
                  } else if (e.key === 'Backspace' && this.tagDraft === '') {
                    this.tags = this.tags.slice(0, -1);
                  }
                }}
                onblur={() => this.commitTagDraft(this.tagDraft, true)}
              />
            )}
          </div>

          <p className="WaterfallUploadModal-setTitleHint">
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.tags_hint', {
              count: MAX_TAGS,
              length: MAX_TAG_LENGTH,
            })}
          </p>
        </div>

        <div
          className={classList('WaterfallUploadModal-dropzone', { 'WaterfallUploadModal-dropzone--dragging': this.dragging })}
          ondragover={(e: DragEvent) => {
            e.preventDefault();

            // A queue item being reordered is not a new file drop.
            if (this.dragKey) {
              return;
            }

            this.dragging = true;
          }}
          ondragleave={() => {
            this.dragging = false;
          }}
          ondrop={(e: DragEvent) => {
            e.preventDefault();
            this.dragging = false;

            if (this.dragKey) {
              this.dragKey = null;
              this.dragOverKey = null;

              return;
            }

            this.addFiles(e.dataTransfer?.files);
          }}
        >
          <i className="fas fa-cloud-upload-alt" aria-hidden="true" />
          <p>{app.translator.trans('lcoy-waterfall.forum.upload_modal.drop_here')}</p>
          <Button className="Button Button--primary" onclick={() => this.pickFiles()}>
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.choose_files')}
          </Button>
          <input
            type="file"
            className="WaterfallUploadModal-fileInput"
            accept="image/jpeg,image/png,image/gif,image/webp"
            multiple
            onchange={(e: Event) => {
              const input = e.target as HTMLInputElement;
              this.addFiles(input.files);
              input.value = '';
            }}
          />
        </div>

        {this.queue.length > 0 && (
          <div className="WaterfallUploadModal-queue" role="list">
            {this.queue.map((item) => (
              <div
                key={item.key}
                className={classList(
                  'WaterfallUploadModal-item',
                  `WaterfallUploadModal-item--${item.status}`,
                  { 'WaterfallUploadModal-item--dragging': this.dragKey === item.key },
                  { 'WaterfallUploadModal-item--over': this.dragOverKey === item.key }
                )}
                role="listitem"
                draggable={item.status === 'ready' ? 'true' : 'false'}
                ondragstart={(e: DragEvent) => {
                  this.dragKey = item.key;
                  this.dragOverKey = null;
                  e.dataTransfer?.setData('text/plain', item.key);

                  if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                  }
                }}
                ondragover={(e: DragEvent) => {
                  if (!this.dragKey || item.status !== 'ready' || this.dragKey === item.key) {
                    return;
                  }

                  e.preventDefault();
                  this.dragOverKey = item.key;

                  if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                  }
                }}
                ondragleave={() => {
                  if (this.dragOverKey === item.key) {
                    this.dragOverKey = null;
                  }
                }}
                ondrop={(e: DragEvent) => {
                  e.preventDefault();
                  e.stopPropagation();
                  this.reorder(this.dragKey, item.key);
                  this.dragKey = null;
                  this.dragOverKey = null;
                }}
                ondragend={() => {
                  this.dragKey = null;
                  this.dragOverKey = null;
                }}
              >
                {item.status === 'ready' && (
                  <div className="WaterfallUploadModal-itemHandle" aria-hidden="true">
                    <i className="fas fa-grip-vertical" />
                  </div>
                )}

                <div className="WaterfallUploadModal-itemIcon">
                  <i className="fas fa-file-image" aria-hidden="true" />
                </div>
                <div className="WaterfallUploadModal-itemBody">
                  <div className="WaterfallUploadModal-itemName" title={item.file.name}>
                    {item.file.name}
                  </div>
                  <div className="WaterfallUploadModal-itemMeta">
                    {item.status === 'ready' && item.width && item.height && <span>{item.width}×{item.height}</span>}
                    {item.status === 'uploading' && <span>{item.progress}%</span>}
                    {item.status === 'done' && (
                      <span className="WaterfallUploadModal-itemDone">
                        <i className="fas fa-check" aria-hidden="true" />
                      </span>
                    )}
                    {item.status === 'error' && <span className="WaterfallUploadModal-itemError">{item.error}</span>}
                  </div>
                  {(item.status === 'uploading' || item.status === 'done') && (
                    <div className="WaterfallUploadModal-progress">
                      <div className="WaterfallUploadModal-progressBar" style={{ width: `${item.progress}%` }} />
                    </div>
                  )}
                </div>
                <div className="WaterfallUploadModal-itemActions">
                  {item.status === 'error' && (
                    <Button
                      className="Button Button--icon"
                      icon="fas fa-redo"
                      aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.retry'))}
                      onclick={() => this.retryItem(item)}
                    />
                  )}
                  {item.status === 'uploading' ? (
                    <Button
                      className="Button Button--icon"
                      icon="fas fa-times"
                      aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.cancel'))}
                      onclick={() => this.cancelItem(item)}
                    />
                  ) : (
                    <Button
                      className="Button Button--icon"
                      icon="fas fa-trash-alt"
                      aria-label={extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.remove'))}
                      onclick={() => {
                        this.queue = this.queue.filter((existing) => existing !== item);

                        if (this.dragKey === item.key) {
                          this.dragKey = null;
                        }

                        if (this.dragOverKey === item.key) {
                          this.dragOverKey = null;
                        }
                      }}
                    />
                  )}
                </div>
              </div>
            ))}
          </div>
        )}

        {total > 0 && (
          <div className="WaterfallUploadModal-overall">
            <span className="WaterfallUploadModal-overallLabel">
              {this.submitting
                ? app.translator.trans('lcoy-waterfall.forum.upload_modal.uploading', { done, total })
                : app.translator.trans('lcoy-waterfall.forum.upload_modal.overall_progress')}
            </span>
            <div className="WaterfallUploadModal-progress WaterfallUploadModal-progress--overall">
              <div className="WaterfallUploadModal-progressBar" style={{ width: `${overall}%` }} />
            </div>
          </div>
        )}

        {allDone && (
          <div className={classList('WaterfallUploadModal-notice', { 'WaterfallUploadModal-notice--error': hasErrors })}>
            {hasErrors
              ? app.translator.trans('lcoy-waterfall.forum.upload_modal.failed_hint')
              : app.translator.trans('lcoy-waterfall.forum.upload_modal.finished')}
          </div>
        )}

        <div className="WaterfallUploadModal-actions">
          <Button
            className="Button Button--primary"
            loading={this.submitting}
            disabled={!this.queue.some((item) => item.status === 'ready')}
            onclick={() => this.submit()}
          >
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.submit')}
          </Button>
          <Button className="Button" onclick={() => this.hide()}>
            {app.translator.trans('lcoy-waterfall.forum.upload_modal.close')}
          </Button>
        </div>
      </div>
    );
  }

  protected pickFiles(): void {
    (this.element?.querySelector('.WaterfallUploadModal-fileInput') as HTMLInputElement | null)?.click();
  }

  /**
   * Commit the tags held in `raw`; see splitTagDraft for the splitting rules.
   */
  protected commitTagDraft(raw: string = this.tagDraft, commitTail = false): void {
    const { tags, draft } = splitTagDraft(raw, this.tags, commitTail);

    this.tags = tags;
    this.tagDraft = draft;
  }

  protected focusTagInput(): void {
    (this.element?.querySelector('.WaterfallUploadModal-tagInput') as HTMLInputElement | null)?.focus();
  }

  protected addFiles(fileList: FileList | null | undefined): void {
    if (!fileList) {
      return;
    }

    for (const file of Array.from(fileList)) {
      if (!file.type.startsWith('image/')) {
        continue;
      }

      const item: QueueItem = {
        key: `wf-${this.keySeq++}`,
        file,
        title: file.name.replace(/\.[^.]+$/, ''),
        width: null,
        height: null,
        thumb: null,
        progress: 0,
        status: 'ready',
        error: null,
        xhr: null,
        cancelled: false,
      };

      this.queue.push(item);
      this.preparing.push(this.prepareItem(item));
    }
  }

  /**
   * Move the dragged item to the drop target's position. The queue order is
   * the display order sent as each file's `position`.
   */
  protected reorder(fromKey: string | null, toKey: string): void {
    if (!fromKey || fromKey === toKey) {
      return;
    }

    const from = this.queue.findIndex((item) => item.key === fromKey);
    const to = this.queue.findIndex((item) => item.key === toKey);

    if (from === -1 || to === -1) {
      return;
    }

    const [moved] = this.queue.splice(from, 1);

    this.queue.splice(to, 0, moved);
    m.redraw();
  }

  // Card thumbnails: the feed renders `thumb ?? src`, so a card-sized copy
  // keeps every grid visit from downloading the full-size original (a phone
  // photo is megabytes; the copy is tens of kilobytes). Files this small gain
  // nothing from one.
  protected static readonly THUMB_MAX_EDGE = 800;
  protected static readonly THUMB_SKIP_BYTES = 150_000;

  /**
   * Decode a queued file once and read everything the queue needs from the
   * decode: the intrinsic size for the label, and the card-sized copy. Runs in
   * the background while the user arranges the queue; submit() waits for it.
   *
   * Never rejects — a file that will not decode here will fail server-side
   * validation anyway, and an item without a copy is still uploadable.
   */
  protected async prepareItem(item: QueueItem): Promise<void> {
    try {
      const source = await this.decodeFile(item.file);

      item.width = source.width;
      item.height = source.height;
      item.thumb = await this.makeThumb(source, item.file);

      if ('close' in source) {
        source.close();
      }
    } catch {
      // A file that will not decode here will fail server-side validation
      // anyway; leaving the label off is enough.
    }

    m.redraw();
  }

  /**
   * Decode with EXIF orientation applied. `from-image` is what makes
   * createImageBitmap respect it; the <img> fallback inherits the same
   * behaviour from the CSS default `image-orientation: from-image`.
   */
  protected async decodeFile(file: File): Promise<ImageBitmap | HTMLImageElement> {
    if ('createImageBitmap' in window) {
      try {
        const options: ImageBitmapOptions = {};

        // 'from-image' postdates this TypeScript's lib.dom (whose union is
        // only "flipY" | "none"); every current engine accepts it, so poke
        // the value through without weakening the rest of the type.
        (options as { imageOrientation?: string }).imageOrientation = 'from-image';

        return await createImageBitmap(file, options);
      } catch {
        // Engines that reject the options bag (or exotic files) fall through
        // to the <img> path below.
      }
    }

    const url = URL.createObjectURL(file);

    try {
      const img = new Image();

      await new Promise<void>((resolve, reject) => {
        img.onload = () => resolve();
        img.onerror = () => reject(new Error('decode failed'));
        img.src = url;
      });

      return img;
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  /**
   * Encode the card-sized copy. GIFs are skipped (a static frame would kill
   * the animation on the card), as are files with nothing to shrink. WebP is
   * asked for first and JPEG kept as the fallback; a browser that cannot
   * encode the requested type silently returns a PNG instead, so the blob's
   * actual type is what decides.
   */
  protected async makeThumb(source: ImageBitmap | HTMLImageElement, file: File): Promise<Blob | null> {
    if (file.type === 'image/gif' || file.size < WaterfallUploadModal.THUMB_SKIP_BYTES) {
      return null;
    }

    const scale = Math.min(1, WaterfallUploadModal.THUMB_MAX_EDGE / Math.max(source.width, source.height));

    if (scale >= 1) {
      return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(source.width * scale);
    canvas.height = Math.round(source.height * scale);

    const context = canvas.getContext('2d');

    if (!context) {
      return null;
    }

    // JPEG has no alpha channel, so a transparent source would come out on a
    // black background in the fallback encoder. White matches what the card
    // shows for a transparent image in a browser that *can* encode WebP.
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);

    for (const type of ['image/webp', 'image/jpeg'] as const) {
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, 0.8));

      if (blob && blob.type === type) {
        return blob;
      }
    }

    return null;
  }

  /**
   * Create the set, then upload every queued file into it in queue order.
   */
  protected submit(): void {
    if (this.submitting) {
      return;
    }

    this.submitting = true;

    // An explicit submit re-arms items the user cancelled earlier: they are
    // back in the `ready` state, so leaving the flag set would make the button
    // do nothing for them. Cancels issued during this run still take effect,
    // because cancelItem() sets the flag while the chain is running.
    this.queue.forEach((item) => {
      if (item.status === 'ready') {
        item.cancelled = false;
      }
    });

    // A tag typed into the field but never confirmed — no Enter, no blur yet —
    // still belongs to the set being published.
    this.commitTagDraft(this.tagDraft, true);

    const title = this.setTitle.replace(/\s+/gu, ' ').trim();

    // Decoding and re-encoding a batch of photos takes a moment, and the
    // upload button is live from the instant the first file lands in the
    // queue. Waiting for the in-flight work here is what makes the card copy
    // deterministic instead of a race the user can lose by clicking quickly.
    // These promises never reject (prepareItem swallows its own failures).
    Promise.all(this.preparing)
      .then(() =>
        app.store
          .createRecord<WaterfallSet>('waterfall-sets')
          .save({ title: title === '' ? null : title, tags: this.tags.length > 0 ? this.tags : null })
      )
      .then((set) => {
        this.set = set;
        this.setAdded = false;

        return this.uploadQueue();
      })
      .then(() => this.finishSubmit())
      .catch((error: unknown) => {
        this.submitting = false;

        const alert = (error as { alert?: AlertAttrs | null } | null)?.alert;

        if (alert) {
          app.alerts.show(alert, alert.content);
        } else {
          app.alerts.show({ type: 'error' }, extractText(app.translator.trans('lcoy-waterfall.forum.upload_modal.set_failed')));
        }

        m.redraw();
      });
  }

  /**
   * Sequential upload keeps per-user concurrency within limits. Cancelled items
   * are skipped: the user explicitly stopped them, so re-uploading the same
   * item right after its abort would defeat the cancel.
   */
  protected uploadQueue(): Promise<void> {
    const uploadNext = (): Promise<void> => {
      const next = this.queue.find((item) => item.status === 'ready' && !item.cancelled);

      if (!next) {
        return Promise.resolve();
      }

      return this.uploadItem(next).then(uploadNext).catch(uploadNext);
    };

    return uploadNext();
  }

  /**
   * Wrap up a submit run. If not a single file made it (all failed or were
   * cancelled), delete the freshly created empty set again instead of leaving
   * a phantom card in the feed.
   */
  protected finishSubmit(): void {
    this.submitting = false;

    const set = this.set;

    if (set && !this.queue.some((item) => item.status === 'done')) {
      const state = this.attrs.waterfallState;

      this.set = null;
      this.setAdded = false;

      // Model.delete() removes the set from the store as part of the request;
      // only the feed array this page owns has to be told separately.
      set
        .delete()
        .then(() => {
          state?.removeSet(set);
        })
        .catch(() => {})
        .then(() => m.redraw());

      return;
    }

    m.redraw();
  }

  protected retryItem(item: QueueItem): void {
    item.cancelled = false;

    // Reuse the current set when it survived (some file succeeded); otherwise
    // a fresh set is created by submit().
    if (this.set && this.setAdded) {
      item.status = 'ready';
      this.uploadItem(item);

      return;
    }

    item.status = 'ready';
    this.submit();
  }

  protected uploadItem(item: QueueItem): Promise<void> {
    const set = this.set;

    if (!set) {
      return Promise.resolve();
    }

    return new Promise((resolve) => {
      const body = new FormData();

      body.append('file', item.file);
      body.append('title', item.title);
      body.append('set_id', String(set.id()));
      body.append('position', String(this.queue.indexOf(item)));

      // The browser-encoded card copy travels in the same request; the queue
      // job forwards it to the image host after the original (see
      // ProcessImageUploadJob::transferThumbnail).
      if (item.thumb) {
        const ext = item.thumb.type.split('/')[1] || 'webp';
        body.append('thumb', item.thumb, `thumb.${ext}`);
      }

      const xhr = new XMLHttpRequest();

      item.xhr = xhr;
      item.status = 'uploading';
      item.progress = 0;
      item.error = null;

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          item.progress = Math.round((e.loaded / e.total) * 100);
          m.redraw();
        }
      };

      xhr.onload = () => {
        item.xhr = null;

        if (xhr.status >= 200 && xhr.status < 300) {
          item.status = 'done';
          item.progress = 100;

          const state = this.attrs.waterfallState;

          // Add the set to the feed once, on the first successful file; it is
          // then polled until the worker finishes the transfers.
          if (state && !this.setAdded) {
            this.setAdded = true;
            state.addUploadedSet(set);
          }
        } else {
          item.status = 'error';
          item.progress = 0;

          try {
            const payload = JSON.parse(xhr.responseText);
            const errors = payload?.errors?.[0]?.detail || payload?.errors?.[0]?.title;

            item.error = typeof errors === 'string' ? errors : `HTTP ${xhr.status}`;
          } catch {
            item.error = `HTTP ${xhr.status}`;
          }
        }

        m.redraw();
        resolve();
      };

      xhr.onerror = () => {
        item.xhr = null;
        item.status = 'error';
        item.progress = 0;
        item.error = extractText(app.translator.trans('core.lib.error.network_error_message'));
        m.redraw();
        resolve();
      };

      // Aborting must resolve the promise too, or the sequential upload
      // chain in submit() would hang forever after a cancel.
      xhr.onabort = () => {
        item.xhr = null;
        resolve();
      };

      xhr.open('POST', `${app.forum.attribute('apiUrl')}/waterfall-images`);
      xhr.setRequestHeader('X-CSRF-Token', app.session.csrfToken);
      xhr.send(body);
    });
  }

  protected cancelItem(item: QueueItem): void {
    item.xhr?.abort();
    item.xhr = null;
    // Flag first, then reset: the abort resolution re-enters the sequential
    // upload chain, which must skip this item (see uploadQueue).
    item.cancelled = true;
    item.status = 'ready';
    item.progress = 0;
  }
}
