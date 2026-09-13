import Extend from 'flarum/common/extenders';
import WaterfallImage from './models/WaterfallImage';
import WaterfallSet from './models/WaterfallSet';
import WaterfallUploadLog from './models/WaterfallUploadLog';

export default [
  // Register the models so `app.store` can hydrate the resources (available
  // to both the forum and admin frontends).
  new Extend.Store() //
    .add('waterfall-images', WaterfallImage)
    .add('waterfall-sets', WaterfallSet)
    .add('waterfall-upload-logs', WaterfallUploadLog),
];
