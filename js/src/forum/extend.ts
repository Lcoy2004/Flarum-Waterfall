import Extend from 'flarum/common/extenders';
import commonExtend from '../common/extend';
import WaterfallPage from './components/WaterfallPage';

export default [
  ...commonExtend,

  // Frontend route: /waterfall, named `lcoy.waterfall`.
  new Extend.Routes() //
    .add('lcoy.waterfall', '/waterfall', WaterfallPage),
];
