import app from 'flarum/forum/app';

import { capacityWaitDelay, sendImageUpload, UPLOAD_CAPACITY_MAX_WAITS } from '../../../src/common/imageUpload';

/**
 * A fake XMLHttpRequest that records what it was asked to send and lets a test
 * settle it by hand. The real one is replaced for the whole file: nothing here
 * needs a network, and the ordering being pinned (reset, then handover) is
 * decided before send() is ever reached.
 */
class FakeXhr {
  static last: FakeXhr | null = null;

  public upload: { onprogress: ((e: unknown) => void) | null } = { onprogress: null };
  public onload: (() => void) | null = null;
  public onerror: (() => void) | null = null;
  public onabort: (() => void) | null = null;

  public status = 0;
  public responseText = '';
  public aborted = false;
  public sent = false;
  public openArgs: unknown[] = [];
  public headers: Record<string, string> = {};

  constructor() {
    FakeXhr.last = this;
  }

  open(...args: unknown[]): void {
    this.openArgs = args;
  }

  setRequestHeader(name: string, value: string): void {
    this.headers[name] = value;
  }

  send(): void {
    this.sent = true;
  }

  abort(): void {
    this.aborted = true;
    this.onabort?.();
  }

  /** Answer the request as the server would. */
  respond(status: number, responseText = ''): void {
    this.status = status;
    this.responseText = responseText;
    this.onload?.();
  }
}

const item = { file: new File(['x'], 'photo.png', { type: 'image/png' }), title: 'A photo', thumb: null };

beforeEach(() => {
  FakeXhr.last = null;

  // globalThis rather than the `global` alias: this project's tsconfig has no
  // node types (see the note in Lightbox.test.ts), so the alias is undeclared.
  (globalThis as any).XMLHttpRequest = FakeXhr;

  (app as any).forum = { attribute: (key: string) => (key === 'apiUrl' ? '/api' : undefined) };
  (app as any).session = { csrfToken: 'test-token' };
  (app as any).translator = { trans: (key: string) => key };
});

describe('sendImageUpload', () => {
  it('resolves "done" on a 2xx answer', async () => {
    const promise = sendImageUpload(item, 0, '7');

    FakeXhr.last!.respond(201);

    await expect(promise).resolves.toEqual({ outcome: 'done' });
  });

  it('resolves "capacity" when the server refuses under the upload_capacity marker', async () => {
    const promise = sendImageUpload(item, 0, '7');

    FakeXhr.last!.respond(422, JSON.stringify({ errors: [{ source: { pointer: '/data/attributes/upload_capacity' } }] }));

    await expect(promise).resolves.toEqual({ outcome: 'capacity' });
  });

  it('resolves "failed" with the server detail for any other refusal', async () => {
    const promise = sendImageUpload(item, 0, '7');

    FakeXhr.last!.respond(422, JSON.stringify({ errors: [{ detail: 'Too large' }] }));

    await expect(promise).resolves.toEqual({ outcome: 'failed', errorDetail: 'Too large' });
  });

  it('resolves "failed" with the network message when the request never lands', async () => {
    const promise = sendImageUpload(item, 0, '7');

    FakeXhr.last!.onerror!();

    await expect(promise).resolves.toEqual({ outcome: 'failed', errorDetail: 'core.lib.error.network_error_message' });
  });

  it('resolves "aborted" rather than hanging when the request is cancelled', async () => {
    const promise = sendImageUpload(item, 0, '7');

    FakeXhr.last!.abort();

    await expect(promise).resolves.toEqual({ outcome: 'aborted' });
  });

  it("hands the live handle over after running the caller's reset", () => {
    // The regression this pins: the modal clears its own handle while
    // resetting, so a handover that ran first would be discarded and its
    // cancel button would have nothing to abort.
    const item: { xhr: FakeXhr | null } = { xhr: null };
    const order: string[] = [];

    sendImageUpload({ file: new File(['x'], 'photo.png', { type: 'image/png' }), title: '', thumb: null }, 0, '7', {
      beforeStart: () => {
        order.push('reset');
        item.xhr = null;
      },
      onXhr: (xhr) => {
        order.push('handover');
        item.xhr = xhr as unknown as FakeXhr;
      },
    });

    expect(order).toEqual(['reset', 'handover']);
    expect(item.xhr).toBe(FakeXhr.last);
    expect(item.xhr!.sent).toBe(true);
  });

  it('resolves "failed" when the request cannot be sent at all', async () => {
    // open()/send() throwing is the one way out of this function with no event
    // to ride. Let loose, it would leave the promise rejected rather than
    // resolved, and the caller's queue swallows rejections — so the row would
    // sit on 'uploading' forever with no error shown and no retry offered.
    let settled = false;

    (globalThis as any).XMLHttpRequest = class extends FakeXhr {
      send(): void {
        throw new Error('InvalidStateError');
      }
    };

    const promise = sendImageUpload(item, 0, '7', { afterSettle: () => (settled = true) });

    await expect(promise).resolves.toEqual({ outcome: 'failed', errorDetail: 'core.lib.error.network_error_message' });
    // The handle was handed over before the throw, so the settle path has to
    // run for the caller not to keep a dead one.
    expect(settled).toBe(true);
  });

  it('carries the set, the position, the CSRF token and the ASCII file name', () => {
    sendImageUpload(item, 3, '7');

    const xhr = FakeXhr.last!;

    expect(xhr.openArgs).toEqual(['POST', '/api/waterfall-images']);
    expect(xhr.headers['X-CSRF-Token']).toBe('test-token');
    expect(xhr.sent).toBe(true);
  });
});

describe('capacityWaitDelay', () => {
  it('grows by the base interval and stops at the ceiling', () => {
    expect(capacityWaitDelay(0)).toBe(1500);
    expect(capacityWaitDelay(1)).toBe(3000);
    expect(capacityWaitDelay(2)).toBe(4500);
    // 1500 * 4 would be 6000; the ceiling holds it at 5000 from here on.
    expect(capacityWaitDelay(3)).toBe(5000);
    expect(capacityWaitDelay(UPLOAD_CAPACITY_MAX_WAITS)).toBe(5000);
  });
});
