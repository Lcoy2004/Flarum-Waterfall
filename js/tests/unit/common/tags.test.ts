import { MAX_TAGS, MAX_TAG_LENGTH, splitTagDraft } from '../../../src/common/tags';

describe('splitTagDraft', () => {
  it('commits the whole field when the input is finished', () => {
    expect(splitTagDraft('风景', [], true)).toEqual({ tags: ['风景'], draft: '' });
  });

  it('keeps the half-written tag in the field while typing', () => {
    // Typing "风景,插画": the comma commits 风景, 插画 is still being written.
    expect(splitTagDraft('风景,插画', [], false)).toEqual({ tags: ['风景'], draft: '插画' });
  });

  it('commits every segment of a pasted list', () => {
    expect(splitTagDraft('风景,插画,测试', [], true)).toEqual({
      tags: ['风景', '插画', '测试'],
      draft: '',
    });
  });

  it('leaves the field empty for a pasted tag without a comma', () => {
    expect(splitTagDraft('风景', [], true)).toEqual({ tags: ['风景'], draft: '' });
  });

  it('trims each tag and ignores blanks', () => {
    expect(splitTagDraft('  风景 ,,插画  ,', [], true)).toEqual({ tags: ['风景', '插画'], draft: '' });
  });

  it('caps the tag length', () => {
    const long = 'a'.repeat(MAX_TAG_LENGTH + 10);

    expect(splitTagDraft(long, [], true).tags).toEqual(['a'.repeat(MAX_TAG_LENGTH)]);
  });

  it('drops duplicates case-insensitively, across the field and against existing tags', () => {
    expect(splitTagDraft('Beach,beach,BEACH', ['beach'], true)).toEqual({ tags: ['beach'], draft: '' });
  });

  it('stops at the tag limit', () => {
    const pasted = ['a', 'b', 'c', 'd', 'e', 'f', 'g'].join(',');

    expect(splitTagDraft(pasted, [], true).tags).toEqual(['a', 'b', 'c', 'd', 'e']);
    expect(splitTagDraft(pasted, [], true).tags.length).toBe(MAX_TAGS);
  });

  it('does not add anything when the field is empty', () => {
    expect(splitTagDraft('', ['风景'], false)).toEqual({ tags: ['风景'], draft: '' });
    expect(splitTagDraft('', ['风景'], true)).toEqual({ tags: ['风景'], draft: '' });
  });

  it('keeps the existing tags untouched', () => {
    const existing = ['风景'];

    expect(splitTagDraft('插画', existing, true)).toEqual({ tags: ['风景', '插画'], draft: '' });
    expect(existing).toEqual(['风景']);
  });
});
