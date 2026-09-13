import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';

interface TestResult {
  ok?: boolean;
  errors?: string[];
  status?: number;
  code?: string;
  message?: string;
  body?: string;
}

/**
 * One-click image host check. It POSTs to the backend test endpoint, which
 * performs a real (file-less) request to the configured image host carrying
 * the same auth/headers a real upload would, then reports an actionable
 * verdict: credentials rejected, credentials accepted, wrong endpoint, etc.
 */
export default class WaterfallConfigTest extends Component {
  protected result: string | null = null;
  protected ok = false;
  protected loading = false;

  view() {
    return (
      <div className="WaterfallConfigTest">
        <Button className="Button" loading={this.loading} onclick={() => this.run()}>
          {app.translator.trans('lcoy-waterfall.admin.settings.test_button')}
        </Button>

        {this.result && (
          <div
            className={classList('WaterfallConfigTest-result', {
              'WaterfallConfigTest-result--ok': this.ok,
              'WaterfallConfigTest-result--err': !this.ok,
            })}
          >
            {this.result}
          </div>
        )}
      </div>
    );
  }

  protected async run(): Promise<void> {
    this.loading = true;
    this.result = null;
    m.redraw();

    try {
      const response = (await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/waterfall/test-host`,
      })) as TestResult;

      this.ok = !!response.ok;
      this.result = this.format(response);
    } catch {
      this.ok = false;
      this.result = this.t('test_error_network');
    }

    this.loading = false;
    m.redraw();
  }

  /**
   * The translated string, ready to be spliced into a message.
   *
   * trans() hands back the formatter's output — always an array, even for a
   * message with no placeholders or rich tags — so it has to be flattened
   * first. Without this the result only reads correctly by accident (a
   * one-element array stringifies to its content) and breaks the moment a
   * translation gains a tag or a parameter.
   */
  protected t(id: string): string {
    return extractText(app.translator.trans(`lcoy-waterfall.admin.settings.${id}`));
  }

  protected format(response: TestResult): string {
    if (response.errors?.length) {
      const messages = response.errors.map((code) => this.mapError(code));

      if (response.message) {
        messages.push(`${this.t('test_error_connection')}: ${response.message}`);
      }

      return messages.join(' ');
    }

    if (response.status !== undefined) {
      switch (response.code) {
        case 'auth_rejected':
          return `${this.t('test_auth_rejected')} (HTTP ${response.status})`;
        case 'auth_ok':
          return this.t('test_auth_ok');
        case 'endpoint_not_found':
        case 'endpoint_wrong_method':
          return `${this.t('test_endpoint_wrong')} (HTTP ${response.status})`;
        case 'ok':
          return this.t('test_success');
        default:
          return `${this.t('test_unexpected')} (HTTP ${response.status})`;
      }
    }

    return this.t('test_error_network');
  }

  protected mapError(code: string): string {
    switch (code) {
      case 'upload_url_empty':
        return this.t('test_error_url');
      case 'upload_url_invalid':
        return this.t('test_error_url_invalid');
      case 'extra_params_invalid':
        return this.t('test_error_params');
      case 'extra_headers_invalid':
        return this.t('test_error_headers');
      case 'connection_error':
        return this.t('test_error_connection');
      default:
        return code;
    }
  }
}
