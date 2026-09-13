import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import type WaterfallImage from './WaterfallImage';

/**
 * Frontend model for the `waterfall-sets` JSON:API resource.
 *
 * A set groups every image chosen in one upload. The grid renders one card per
 * set (cover image + image count badge) and the lightbox browses its `images`
 * in the uploader-defined `position` order.
 */
export default class WaterfallSet extends Model {
  title() {
    return Model.attribute<string | null | undefined>('title').call(this);
  }
  tags() {
    return Model.attribute<string[], string[] | null | undefined>('tags', (value) => value ?? []).call(this);
  }
  imagesCount() {
    return Model.attribute<number, number | null | undefined>('imagesCount', (value) => value ?? 0).call(this);
  }
  likesCount() {
    return Model.attribute<number, number | null | undefined>('likesCount', (value) => value ?? 0).call(this);
  }
  viewsCount() {
    return Model.attribute<number, number | null | undefined>('viewsCount', (value) => value ?? 0).call(this);
  }
  score() {
    return Model.attribute<number, number | null | undefined>('score', (value) => value ?? 0).call(this);
  }
  status() {
    return Model.attribute<string, string | null | undefined>('status', (value) => value ?? 'pending').call(this);
  }
  createdAt() {
    return Model.attribute<Date | undefined, string | undefined>('createdAt', Model.transformDate).call(this);
  }
  canRename() {
    return Model.attribute<boolean | undefined>('canRename').call(this);
  }
  canDelete() {
    return Model.attribute<boolean | undefined>('canDelete').call(this);
  }

  user() {
    return Model.hasOne<User | null>('user').call(this);
  }
  coverImage() {
    return Model.hasOne<WaterfallImage | null>('coverImage').call(this);
  }
  images() {
    return Model.hasMany<WaterfallImage>('images').call(this);
  }

  /**
   * The image rendered as the card cover (falls back to the first loaded
   * image when the server has not resolved a cover yet).
   */
  cover(): WaterfallImage | null {
    const cover = this.coverImage();

    if (cover) {
      return cover;
    }

    const images = this.images();

    return (images && images[0]) || null;
  }
}
