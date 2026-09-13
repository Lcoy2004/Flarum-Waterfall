import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';

/**
 * Frontend model for the `waterfall-images` JSON:API resource.
 */
export default class WaterfallImage extends Model {
  src() {
    return Model.attribute<string>('src').call(this);
  }
  thumb() {
    return Model.attribute<string | null>('thumb').call(this);
  }
  title() {
    return Model.attribute<string | null | undefined>('title').call(this);
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
  error() {
    return Model.attribute<string | null | undefined>('error').call(this);
  }
  createdAt() {
    return Model.attribute<Date | undefined, string | undefined>('createdAt', Model.transformDate).call(this);
  }
  canLike() {
    return Model.attribute<boolean | undefined>('canLike').call(this);
  }
  canDelete() {
    return Model.attribute<boolean | undefined>('canDelete').call(this);
  }
  isLiked() {
    return Model.attribute<boolean>('isLiked', (value) => !!value).call(this);
  }

  user() {
    return Model.hasOne<User | null>('user').call(this);
  }
  likes() {
    return Model.hasMany<User>('likes').call(this);
  }

  /**
   * The effective image to render in the grid (thumb falls back to src).
   */
  displaySrc() {
    return this.thumb() || this.src();
  }
}
