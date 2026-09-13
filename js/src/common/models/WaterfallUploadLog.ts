import Model from 'flarum/common/Model';

/**
 * Frontend model for the `waterfall-upload-logs` JSON:API resource
 * (admin only).
 */
export default class WaterfallUploadLog extends Model {
  imageId() {
    return Model.attribute<number | null>('imageId').call(this);
  }
  status() {
    return Model.attribute<string>('status').call(this);
  }
  httpCode() {
    return Model.attribute<number | null | undefined>('httpCode').call(this);
  }
  durationMs() {
    return Model.attribute<number | null | undefined>('durationMs').call(this);
  }
  attempts() {
    return Model.attribute<number, number | null | undefined>('attempts', (value) => value ?? 0).call(this);
  }
  error() {
    return Model.attribute<string | null | undefined>('error').call(this);
  }
  createdAt() {
    return Model.attribute<Date | undefined, string | undefined>('createdAt', Model.transformDate).call(this);
  }
}
