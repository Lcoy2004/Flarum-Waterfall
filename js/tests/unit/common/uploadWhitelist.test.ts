import { acceptListForWhitelist } from '../../../src/common/uploadWhitelist';

describe('acceptListForWhitelist', () => {
  it('maps the default whitelist to its MIME types', () => {
    expect(acceptListForWhitelist('jpg,jpeg,png,gif,webp')).toBe('image/jpeg,image/png,image/gif,image/webp');
  });

  it('collapses jpg and jpeg into one image/jpeg entry', () => {
    expect(acceptListForWhitelist('jpg,jpeg')).toBe('image/jpeg');
  });

  it('trims whitespace and lowercases entries', () => {
    expect(acceptListForWhitelist(' PNG , WebP ')).toBe('image/png,image/webp');
  });

  it('skips empty segments', () => {
    expect(acceptListForWhitelist('png,,webp')).toBe('image/png,image/webp');
  });

  it('falls back to every image type for an empty whitelist', () => {
    expect(acceptListForWhitelist('')).toBe('image/*');
    expect(acceptListForWhitelist(' , ')).toBe('image/*');
  });
});
