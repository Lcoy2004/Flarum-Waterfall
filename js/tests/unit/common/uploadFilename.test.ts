import { uploadExtension } from '../../../src/common/uploadFilename';

describe('uploadExtension', () => {
  it('uses the MIME subtype when the browser typed the file', () => {
    expect(uploadExtension('image/png')).toBe('png');
    expect(uploadExtension('image/jpeg')).toBe('jpeg');
    expect(uploadExtension('image/webp')).toBe('webp');
  });

  it('falls back to the name for a file the browser could not type', () => {
    expect(uploadExtension('', 'photo.PNG')).toBe('png');
    expect(uploadExtension('', 'holiday.tar.gz')).toBe('gz');
  });

  it('never returns a non-ASCII or punctuated extension', () => {
    expect(uploadExtension('', '照片.图片')).toBe('jpg');
    expect(uploadExtension('', 'a.j"p')).toBe('jpg');
    expect(uploadExtension('', 'no-extension')).toBe('jpg');
    expect(uploadExtension('image/')).toBe('jpg');
  });

  it('ignores an over-long tail that cannot be an extension', () => {
    expect(uploadExtension('', 'archive.verylongextension')).toBe('jpg');
  });
});
