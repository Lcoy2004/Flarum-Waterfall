import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

import type WaterfallUploadLog from '../../common/models/WaterfallUploadLog';

/**
 * Read-only admin panel listing the last 100 image host transfers: status,
 * HTTP response code, duration, attempts and failure reason.
 */
export default class WaterfallUploadLogPanel extends Component {
  protected logs: WaterfallUploadLog[] | null = null;
  protected loading = false;

  oninit(vnode: Mithril.Vnode<Record<string, unknown>, this>) {
    super.oninit(vnode);
    this.load();
  }

  view() {
    return (
      <div className="WaterfallUploadLogPanel">
        <div className="WaterfallUploadLogPanel-header">
          <h4>{app.translator.trans('lcoy-waterfall.admin.upload_log.title')}</h4>
          <Button
            className="Button Button--icon"
            icon="fas fa-sync"
            aria-label={app.translator.trans('lcoy-waterfall.admin.upload_log.refresh')}
            onclick={() => this.load()}
          />
        </div>

        {this.loading && <LoadingIndicator />}

        {this.logs !== null && this.logs.length === 0 && (
          <p className="WaterfallUploadLogPanel-empty">{app.translator.trans('lcoy-waterfall.admin.upload_log.empty')}</p>
        )}

        {this.logs !== null && this.logs.length > 0 && (
          <div className="WaterfallUploadLogPanel-tableWrapper">
            <table className="WaterfallUploadLogPanel-table">
              <thead>
                <tr>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.image_id')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.status')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.http_code')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.duration')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.attempts')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.error')}</th>
                  <th>{app.translator.trans('lcoy-waterfall.admin.upload_log.time')}</th>
                </tr>
              </thead>
              <tbody>
                {this.logs.map((log) => (
                  <tr key={log.id()} className={classList('WaterfallUploadLogPanel-row', `WaterfallUploadLogPanel-row--${log.status()}`)}>
                    <td>#{log.imageId()}</td>
                    <td>
                      <span className={classList('WaterfallUploadLogPanel-badge', `badge-${log.status()}`)}>
                        {app.translator.trans(`lcoy-waterfall.admin.upload_log.status_${log.status()}`)}
                      </span>
                    </td>
                    <td>{log.httpCode() ?? '—'}</td>
                    <td>
                      {log.durationMs() != null
                        ? app.translator.trans('lcoy-waterfall.admin.upload_log.milliseconds', { ms: log.durationMs() })
                        : '—'}
                    </td>
                    <td>{log.attempts()}</td>
                    <td className="WaterfallUploadLogPanel-error">{log.error() || '—'}</td>
                    <td>{log.createdAt()?.toLocaleString() ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  protected load(): void {
    this.loading = true;

    // The resource already exposes an admin-only Index endpoint, so ask the
    // store for it: that is what registers the models and keeps payload
    // handling in one place, instead of hand-rolling app.request plus
    // pushPayload here (and re-deriving the model array it returns).
    app.store
      .find<WaterfallUploadLog[]>('waterfall-upload-logs', { page: { limit: 100 } })
      .then((logs) => {
        this.logs = logs;
      })
      .catch(() => {
        this.logs = [];
      })
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
