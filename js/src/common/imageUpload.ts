import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

import { uploadExtension } from './uploadFilename';
import { isCapacityRejection, readErrorDetail } from './uploadErrors';

/**
 * Transfer one queued file to the upload API endpoint.
 *
 * Pure XHR logic — no Mithril, no modal state. The caller (the upload modal)
 * reflects progress and the outcome on its own queue items through the
 * callbacks; the browser-encoded card copy travels in the same request, and
 * the queue job forwards it to the image host after the original.
 */
type ImageUploadResult = { outcome: 'done' } | { outcome: 'capacity' } | { outcome: 'failed'; errorDetail: string } | { outcome: 'aborted' };

interface ImageUploadCallbacks {
  /** XHR upload progress, 0-100. Fires on a background thread. */
  onProgress?: (progress: number) => void;
  /** Reset the item to a fresh state before the request goes out. */
  beforeStart?: () => void;
  /** The request has settled (loaded, errored or aborted). */
  afterSettle?: () => void;
  /** Hands over the in-flight XHR so the caller can abort it (cancel). */
  onXhr?: (xhr: XMLHttpRequest) => void;
}

/**
 * How long a caller waits for the uploader's own in-flight images to finish,
 * and how many times it asks again before giving up.
 *
 * The wait ends when a transfer completes, and one transfer is two requests
 * to an external host that is allowed up to `upload_timeout` (30s) each — so
 * the budget has to cover the slow end of that, not the quick one.
 *
 * The interval grows up to a ceiling instead of growing without bound, so a
 * long wait keeps asking often enough to take a slot the moment one appears.
 */
export const UPLOAD_CAPACITY_MAX_WAITS = 20;
const UPLOAD_CAPACITY_WAIT_MS = 1500;
const UPLOAD_CAPACITY_WAIT_MAX_MS = 5000;

export function capacityWaitDelay(attempt: number): number {
  return Math.min(UPLOAD_CAPACITY_WAIT_MS * (attempt + 1), UPLOAD_CAPACITY_WAIT_MAX_MS);
}

/** The translated message for running out of capacity retries. */
export function capacityExhaustedMessage(): string {
  return extractText(app.translator.trans('lcoy-waterfall.api.errors.concurrency_limit'));
}

/**
 * The translated network-error message, for the XHR error path where the
 * server never answered.
 */
function networkErrorMessage(): string {
  return extractText(app.translator.trans('core.lib.error.network_error_message'));
}

export function sendImageUpload(
  item: { file: File; title: string; thumb: Blob | null },
  position: number,
  setId: string,
  callbacks: ImageUploadCallbacks = {}
): Promise<ImageUploadResult> {
  return new Promise((resolve) => {
    const body = new FormData();

    // Both parts travel under an ASCII-only name: the browser would
    // otherwise put the file's own name — Chinese, and possibly quoted —
    // into the multipart header, which the site's WAF can read as a
    // malformed request and answer by blocking the uploader's IP. Only
    // the extension is load-bearing (see uploadExtension).
    body.append('file', item.file, `image.${uploadExtension(item.file.type, item.file.name)}`);
    body.append('title', item.title);
    body.append('set_id', setId);
    body.append('position', String(position));

    if (item.thumb) {
      body.append('thumb', item.thumb, `thumb.${uploadExtension(item.thumb.type)}`);
    }

    const xhr = new XMLHttpRequest();

    // The reset runs first and the handover second, and the order is
    // load-bearing: a caller that clears its own handle while resetting would
    // otherwise drop the reference this callback just gave it, leaving its
    // cancel button with nothing to abort.
    callbacks.beforeStart?.();
    callbacks.onXhr?.(xhr);

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        callbacks.onProgress?.(Math.round((e.loaded / e.total) * 100));
      }
    };

    xhr.onload = () => {
      callbacks.afterSettle?.();

      if (xhr.status >= 200 && xhr.status < 300) {
        resolve({ outcome: 'done' });

        return;
      }

      if (isCapacityRejection(xhr.responseText)) {
        resolve({ outcome: 'capacity' });

        return;
      }

      resolve({ outcome: 'failed', errorDetail: readErrorDetail(xhr) });
    };

    xhr.onerror = () => {
      callbacks.afterSettle?.();
      resolve({ outcome: 'failed', errorDetail: networkErrorMessage() });
    };

    // Aborting must resolve the promise too, or the sequential upload chain
    // a caller builds on top of this would hang forever after a cancel.
    xhr.onabort = () => {
      callbacks.afterSettle?.();
      resolve({ outcome: 'aborted' });
    };

    // Sending can fail before a request is ever made — open() rejects a URL it
    // cannot parse, send() a state it cannot post from. Without this the throw
    // escapes the executor as a rejection, and the caller's chain swallows
    // rejections (WaterfallUploadModal::uploadQueue catches to keep walking the
    // queue): the row would be left on 'uploading' with no error to read and no
    // retry offered, since the retry button only appears for 'error'. Answering
    // 'failed' here turns that into something the uploader can act on.
    try {
      xhr.open('POST', `${app.forum.attribute('apiUrl')}/waterfall-images`);
      xhr.setRequestHeader('X-CSRF-Token', app.session.csrfToken);
      xhr.send(body);
    } catch {
      callbacks.afterSettle?.();
      resolve({ outcome: 'failed', errorDetail: networkErrorMessage() });
    }
  });
}
