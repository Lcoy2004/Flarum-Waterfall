import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import commonExtend from '../common/extend';
import WaterfallUploadLogPanel from './components/WaterfallUploadLogPanel';
import WaterfallConfigTest from './components/WaterfallConfigTest';

export default [
  ...commonExtend,

  new Extend.Admin()
    // Permissions, assignable on the admin permissions page.
    .permission(
      () => ({
        icon: 'fas fa-cloud-upload-alt',
        label: app.translator.trans('lcoy-waterfall.admin.permissions.upload'),
        permission: 'lcoy-waterfall.upload',
      }),
      'start',
      90
    )
    .permission(
      () => ({
        icon: 'fas fa-heart',
        label: app.translator.trans('lcoy-waterfall.admin.permissions.like'),
        permission: 'lcoy-waterfall.like',
      }),
      'reply',
      90
    )
    .permission(
      () => ({
        icon: 'fas fa-images',
        label: app.translator.trans('lcoy-waterfall.admin.permissions.moderate'),
        permission: 'lcoy-waterfall.moderate',
      }),
      'moderate',
      90
    )
    // -- Image host ------------------------------------------------------
    .customSetting(
      () => <h3 className="WaterfallSettings-heading">{app.translator.trans('lcoy-waterfall.admin.settings.image_host_heading')}</h3>,
      100
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.upload_url',
        type: 'text',
        label: app.translator.trans('lcoy-waterfall.admin.settings.upload_url_label'),
        placeholder: app.translator.trans('lcoy-waterfall.admin.settings.upload_url_placeholder'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.upload_url_help'),
      }),
      99
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.extra_params',
        type: 'textarea',
        label: app.translator.trans('lcoy-waterfall.admin.settings.extra_params_label'),
        placeholder: app.translator.trans('lcoy-waterfall.admin.settings.extra_params_placeholder'),
      }),
      98
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.extra_headers',
        type: 'textarea',
        label: app.translator.trans('lcoy-waterfall.admin.settings.extra_headers_label'),
        placeholder: app.translator.trans('lcoy-waterfall.admin.settings.extra_headers_placeholder'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.extra_headers_help'),
      }),
      97
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.basic_auth_user',
        type: 'text',
        label: app.translator.trans('lcoy-waterfall.admin.settings.basic_auth_user_label'),
      }),
      96
    )
    // Password field: never echoes the stored value back (Flarum writes the
    // setting only when a non-empty value is submitted).
    .setting(
      () => ({
        setting: 'lcoy-waterfall.basic_auth_pass',
        type: 'password',
        label: app.translator.trans('lcoy-waterfall.admin.settings.basic_auth_pass_label'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.basic_auth_help'),
      }),
      95
    )
    // One-click sanity check for the image host settings above.
    .customSetting(() => <WaterfallConfigTest />, 94)
    // -- Upload limits ---------------------------------------------------
    .customSetting(() => <h3 className="WaterfallSettings-heading">{app.translator.trans('lcoy-waterfall.admin.settings.limits_heading')}</h3>, 90)
    .setting(
      () => ({
        setting: 'lcoy-waterfall.mime_whitelist',
        type: 'text',
        label: app.translator.trans('lcoy-waterfall.admin.settings.mime_whitelist_label'),
      }),
      89
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.max_size_mb',
        type: 'number',
        min: 1,
        label: app.translator.trans('lcoy-waterfall.admin.settings.max_size_label'),
      }),
      88
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.user_hourly_limit',
        type: 'number',
        min: 0,
        label: app.translator.trans('lcoy-waterfall.admin.settings.user_hourly_label'),
      }),
      87
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.global_per_minute_limit',
        type: 'number',
        min: 0,
        label: app.translator.trans('lcoy-waterfall.admin.settings.global_minute_label'),
      }),
      86
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.user_concurrent_uploads',
        type: 'number',
        min: 1,
        label: app.translator.trans('lcoy-waterfall.admin.settings.concurrent_label'),
      }),
      85
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.upload_timeout',
        type: 'number',
        min: 5,
        label: app.translator.trans('lcoy-waterfall.admin.settings.timeout_label'),
      }),
      84
    )
    // -- Recommendation algorithm ----------------------------------------
    .customSetting(() => <h3 className="WaterfallSettings-heading">{app.translator.trans('lcoy-waterfall.admin.settings.recommend_heading')}</h3>, 80)
    .setting(
      () => ({
        setting: 'lcoy-waterfall.weight_likes',
        type: 'number',
        step: '0.1',
        label: app.translator.trans('lcoy-waterfall.admin.settings.weight_likes_label'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.recommend_help'),
      }),
      79
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.weight_views',
        type: 'number',
        step: '0.1',
        label: app.translator.trans('lcoy-waterfall.admin.settings.weight_views_label'),
      }),
      78
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.weight_recency',
        type: 'number',
        step: '0.1',
        label: app.translator.trans('lcoy-waterfall.admin.settings.weight_recency_label'),
      }),
      77
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.decay_lambda',
        type: 'number',
        step: '0.01',
        label: app.translator.trans('lcoy-waterfall.admin.settings.decay_lambda_label'),
      }),
      76
    )
    // -- Frontend display ------------------------------------------------
    .customSetting(() => <h3 className="WaterfallSettings-heading">{app.translator.trans('lcoy-waterfall.admin.settings.display_heading')}</h3>, 70)
    // The page intro sits first in this group because it is the one setting
    // here that visitors actually read.
    .setting(
      () => ({
        setting: 'lcoy-waterfall.description',
        type: 'textarea',
        label: app.translator.trans('lcoy-waterfall.admin.settings.description_label'),
        placeholder: app.translator.trans('lcoy-waterfall.admin.settings.description_placeholder'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.description_help'),
      }),
      69
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.per_page',
        type: 'number',
        min: 1,
        max: 100,
        label: app.translator.trans('lcoy-waterfall.admin.settings.per_page_label'),
      }),
      68
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.card_radius',
        type: 'number',
        min: 0,
        label: app.translator.trans('lcoy-waterfall.admin.settings.card_radius_label'),
      }),
      67
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.card_gutter',
        type: 'number',
        min: 0,
        label: app.translator.trans('lcoy-waterfall.admin.settings.card_gutter_label'),
      }),
      66
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.show_like_button',
        type: 'bool',
        label: app.translator.trans('lcoy-waterfall.admin.settings.show_like_button_label'),
      }),
      65
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.slideshow_images',
        type: 'number',
        min: 0,
        max: 10,
        label: app.translator.trans('lcoy-waterfall.admin.settings.slideshow_images_label'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.slideshow_images_help'),
      }),
      64
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.local_relay',
        type: 'bool',
        label: app.translator.trans('lcoy-waterfall.admin.settings.local_relay_label'),
        help: app.translator.trans('lcoy-waterfall.admin.settings.local_relay_help'),
      }),
      63
    )
    .setting(
      () => ({
        setting: 'lcoy-waterfall.poll_interval',
        type: 'number',
        min: 2,
        label: app.translator.trans('lcoy-waterfall.admin.settings.poll_interval_label'),
      }),
      62
    )
    // Read-only upload log panel (last 100 image host transfers).
    .customSetting(() => <WaterfallUploadLogPanel />, 10),
];
