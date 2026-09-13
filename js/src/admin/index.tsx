import app from 'flarum/admin/app';

export { default as extend } from './extend';

app.initializers.add('lcoy-waterfall', () => {
  // All settings, permissions and the upload log panel are registered via
  // the Extend.Admin() extender in ./extend.tsx.
});

import './admin';
