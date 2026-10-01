import { isCapacityRejection, readErrorDetail } from '../../../src/common/uploadErrors';

describe('readErrorDetail', () => {
  const xhrWith = (status: number, responseText: string): XMLHttpRequest => ({ status, responseText } as XMLHttpRequest);

  it('prefers the detail of the first JSON:API error', () => {
    const xhr = xhrWith(422, JSON.stringify({ errors: [{ detail: 'The file is too large.', title: 'Unprocessable' }] }));

    expect(readErrorDetail(xhr)).toBe('The file is too large.');
  });

  it('falls back to the title when detail is absent', () => {
    const xhr = xhrWith(422, JSON.stringify({ errors: [{ title: 'Unprocessable' }] }));

    expect(readErrorDetail(xhr)).toBe('Unprocessable');
  });

  it('falls back to the HTTP status for a non-JSON body', () => {
    const xhr = xhrWith(500, '<html>Server Error</html>');

    expect(readErrorDetail(xhr)).toBe('HTTP 500');
  });

  it('falls back to the HTTP status for an empty body', () => {
    const xhr = xhrWith(0, '');

    expect(readErrorDetail(xhr)).toBe('HTTP 0');
  });
});

describe('isCapacityRejection', () => {
  it('is true for the upload_capacity source pointer', () => {
    expect(isCapacityRejection(JSON.stringify({ errors: [{ source: { pointer: '/data/attributes/upload_capacity' } }] }))).toBe(true);
  });

  it('is false for another pointer', () => {
    expect(isCapacityRejection(JSON.stringify({ errors: [{ source: { pointer: '/data/attributes/file' } }] }))).toBe(false);
  });

  it('is false for a missing pointer', () => {
    expect(isCapacityRejection(JSON.stringify({ errors: [{ detail: 'nope' }] }))).toBe(false);
  });

  it('is false for a non-JSON body', () => {
    expect(isCapacityRejection('not json')).toBe(false);
    expect(isCapacityRejection('')).toBe(false);
  });
});
