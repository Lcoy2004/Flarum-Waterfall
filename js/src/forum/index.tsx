import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';

export { default as extend } from './extend';

app.initializers.add('lcoy-waterfall', () => {
  // Sidebar entry on the index sidebar (the standard Flarum nav extension
  // point — visible wherever IndexSidebar renders).
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    items.add(
      'waterfall',
      <LinkButton icon="fas fa-images" href={app.route('lcoy.waterfall')}>
        {app.translator.trans('lcoy-waterfall.forum.page.nav')}
      </LinkButton>,
      // Core "allDiscussions" is 100; keep waterfall right after the primary
      // nav items so it stays above the fold even with many tag links below.
      90
    );
  });
});

import './forum';
