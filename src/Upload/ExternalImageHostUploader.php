<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Upload;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Lcoy\Waterfall\Upload\Exception\UploadException;
use Psr\Log\LoggerInterface;

/**
 * Transfers a locally staged (temporary, non web-accessible) file to the
 * configured external image host and returns the `src` path from its response.
 *
 * Contract of the image host (fixed):
 *   POST {upload_url}                      multipart/form-data
 *   file field:                            `file`
 *   optional Basic auth header             Authorization: Basic base64(user:pass)
 *   200 response:                          JSON array, e.g. [{"src":"/file/abc.png"}]
 */
class ExternalImageHostUploader
{
    // Guzzle directly instead of the Laravel HTTP facade: it is a hard
    // dependency of Flarum core and gives precise control over multipart
    // streaming, per-attempt timeouts and retry conditions.
    //
    // One client for the lifetime of the instance (and the container binds
    // this as a singleton, so that means the lifetime of the queue worker).
    // A client per transfer would open a fresh connection every time — and one
    // uploaded image costs two transfers, the original plus its card copy —
    // so reusing it keeps the connection (and its TLS session) to the image
    // host alive instead of paying a handshake twice per image.
    protected ?Client $client = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * The shared client. http_errors is off so 4xx/5xx arrive as responses
     * (the status is interpreted per attempt in upload()); the timeouts are
     * per-request because they come from the admin settings.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client(['http_errors' => false]);
    }

    /**
     * Upload a file to the image host with retry (exponential backoff, max 3
     * attempts) and return the resulting `src` path.
     *
     * $budgetSeconds caps the whole transfer — every attempt and the backoff
     * between them. A caller running inside a queue job must pass one: Flarum
     * builds its database queue with a hardcoded retry_after of 60 seconds,
     * so a job that overruns it has the same transfer handed to a second
     * worker while the first is still running, and the host ends up with two
     * copies of the file.
     *
     * @throws UploadException on final failure
     */
    public function upload(string $filePath, string $filename, ?int $budgetSeconds = null): UploadResult
    {
        $url = trim((string) $this->settings->get('lcoy-waterfall.upload_url', ''));
        // Floored like every other setting: Guzzle reads 0 (or a negative
        // value) as "no timeout", which would let a host that stops answering
        // hold a queue worker — and the staged file — for as long as the
        // process survives.
        $configuredTimeout = max(1, (int) $this->settings->get('lcoy-waterfall.upload_timeout', 30));

        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new UploadException('image_host_not_configured', 'The image host upload URL is not configured.');
        }

        // filter_var accepts file://, ftp:// and friends, and the transfer runs
        // inside the worker, where a stray scheme could be pointed at the local
        // filesystem or an internal address. The admin UI already documents
        // http/https, so this only enforces what the setting promises.
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new UploadException('image_host_not_configured', 'The image host upload URL must use http or https.');
        }

        $client = $this->client();

        $attempts = 0;
        $maxAttempts = 3;
        $startedAt = microtime(true);
        $lastException = null;

        while ($attempts < $maxAttempts) {
            // What is left of the caller's budget, whole seconds; null when it
            // set none.
            $remaining = $budgetSeconds === null ? null : (int) floor($budgetSeconds - (microtime(true) - $startedAt));

            if ($remaining !== null && $remaining < 1) {
                // Starting another attempt could not finish inside the budget,
                // and overrunning it is exactly what gets the transfer
                // duplicated by the queue. A real attempt failure is left as
                // the reason; otherwise say what actually happened.
                $lastException ??= new UploadException(
                    'image_host_timeout',
                    'The upload ran out of time before the image host answered.',
                    0,
                    $attempts,
                    (int) round((microtime(true) - $startedAt) * 1000)
                );

                break;
            }

            $attempts++;

            // An attempt gets the configured timeout, never more than what is
            // left of the budget.
            $timeout = $remaining === null ? $configuredTimeout : min($configuredTimeout, $remaining);

            try {
                // Built per attempt on purpose: the multipart body opens the
                // file, and a retry needs a fresh handle because the previous
                // one was consumed by the attempt that failed. Timeouts ride
                // along per request (http_errors is off on the client).
                $response = $client->post($url, $this->buildRequestOptions($filePath, $filename) + [
                    'timeout' => $timeout,
                    'connect_timeout' => min(10, $timeout),
                ]);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                // Network errors, timeouts and 5xx responses are retried.
                $this->logger->warning('lcoy-waterfall: image host attempt {attempt} failed: {message}', [
                    'attempt' => $attempts,
                    'message' => $e->getMessage(),
                ]);

                $lastException = $e;

                if ($attempts < $maxAttempts) {
                    // Exponential backoff: 1s, 2s.
                    usleep(1000000 * (2 ** ($attempts - 1)));
                }

                continue;
            }

            $httpCode = $response->getStatusCode();

            if ($httpCode >= 500 && $attempts < $maxAttempts) {
                $this->logger->warning('lcoy-waterfall: image host returned {code}, retrying', ['code' => $httpCode]);
                usleep(1000000 * (2 ** ($attempts - 1)));
                continue;
            }

            if ($httpCode !== 200) {
                throw new UploadException(
                    'image_host_http_error',
                    "The image host responded with HTTP {$httpCode}.",
                    $httpCode,
                    $attempts,
                    (int) round((microtime(true) - $startedAt) * 1000)
                );
            }

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);

            if (! is_array($decoded)) {
                throw new UploadException(
                    'image_host_invalid_response',
                    'The image host response was not valid JSON.',
                    $httpCode,
                    $attempts,
                    (int) round((microtime(true) - $startedAt) * 1000)
                );
            }

            // Response shape: [{"src": "/file/abc.png"}]. Accept both a
            // top-level array of objects and an object with src/thumb keys.
            $entry = isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : $decoded;

            // Telegraph-Image (and several other hosts) return a root-relative
            // src like "/file/abc.png" — resolve it against the host so the
            // frontend renders an absolute URL.
            $src = $this->resolveSrc($entry['src'] ?? null, $url);
            $thumb = $this->resolveSrc($entry['thumb'] ?? null, $url);

            if ($src === null) {
                throw new UploadException(
                    'image_host_missing_src',
                    'The image host response did not contain a "src" path.',
                    $httpCode,
                    $attempts,
                    (int) round((microtime(true) - $startedAt) * 1000)
                );
            }

            return new UploadResult(
                src: $src,
                httpCode: $httpCode,
                attempts: $attempts,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
                thumb: $thumb
            );
        }

        throw new UploadException(
            'image_host_unreachable',
            $lastException ? $lastException->getMessage() : 'The image host could not be reached.',
            0,
            $attempts,
            (int) round((microtime(true) - $startedAt) * 1000)
        );
    }

    protected function buildRequestOptions(string $filePath, string $filename): array
    {
        $options = [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => $filename,
                ],
            ],
            // Upstream image hosts commonly redirect short links; follow up to 3.
            'allow_redirects' => ['max' => 3],
        ];

        // Extra parameters: a JSON object of key/value pairs appended as
        // additional multipart form fields.
        $extraParams = json_decode((string) $this->settings->get('lcoy-waterfall.extra_params', ''), true);

        if (is_array($extraParams)) {
            foreach ($extraParams as $key => $value) {
                if (is_scalar($value)) {
                    $options['multipart'][] = [
                        'name' => (string) $key,
                        'contents' => (string) $value,
                    ];
                }
            }
        }

        // Extra headers: a JSON object of name/value pairs (e.g. Bearer
        // tokens for hosts like Lsky Pro).
        $extraHeaders = json_decode((string) $this->settings->get('lcoy-waterfall.extra_headers', ''), true);

        if (is_array($extraHeaders)) {
            $options['headers'] = [];

            foreach ($extraHeaders as $name => $value) {
                if (is_scalar($value)) {
                    $options['headers'][(string) $name] = (string) $value;
                }
            }
        }

        $basicUser = (string) $this->settings->get('lcoy-waterfall.basic_auth_user', '');
        $basicPass = (string) $this->settings->get('lcoy-waterfall.basic_auth_pass', '');

        if ($basicUser !== '') {
            $options['auth'] = [$basicUser, $basicPass];
        }

        return $options;
    }

    /**
     * Normalise a host-returned src/thumb value into an absolute, scheme-safe
     * URL. Root-relative paths (Telegraph-Image style "/file/x.png") are
     * resolved against the upload URL's host; unsafe schemes yield null.
     */
    protected function resolveSrc(mixed $value, string $uploadUrl): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Reject protocol-relative values ("//host/x.png"): they point at a
        // different host and must not be treated as root-relative paths.
        if (str_starts_with($value, '//')) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            $parts = parse_url($uploadUrl);

            if (empty($parts['host'])) {
                return null;
            }

            // Keep the port when the host is on a non-default one; dropping it
            // would resolve "/file/x.png" against the wrong host.
            $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            $value = $origin.$value;
        }

        return $this->srcHasSafeScheme($value) ? $value : null;
    }

    /**
     * Only http/https absolute URLs and single-slash root-relative paths are
     * allowed (a leading "//" would be protocol-relative, i.e. another host).
     */
    public function srcHasSafeScheme(string $src): bool
    {
        if (str_starts_with($src, '/')) {
            return ! str_starts_with($src, '//');
        }

        $scheme = parse_url($src, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }
}
