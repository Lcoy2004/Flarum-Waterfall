<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Laminas\Diactoros\Response\JsonResponse;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin-only one-click image host connectivity/auth check.
 *
 * Sends a file-less POST to the configured upload URL (carrying the same Basic
 * auth and extra headers a real upload would) and interprets the status so the
 * admin gets an actionable result instead of a raw curl error:
 *
 *   401 -> credentials rejected
 *   400/422 -> credentials accepted (missing file is expected)
 *   405 -> upload URL points at the wrong endpoint
 *   transport error -> host unreachable / TLS problem
 */
class TestImageHostController implements RequestHandlerInterface
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $url = trim((string) $this->settings->get('lcoy-waterfall.upload_url', ''));

        $errors = [];

        if ($url === '') {
            $errors[] = 'upload_url_empty';
        } elseif (! filter_var($url, FILTER_VALIDATE_URL) || ! ExternalImageHostUploader::urlUsesHttpScheme($url)) {
            // The same rule a real upload enforces: a scheme like ftp:// or
            // file:// passes filter_var, and the test exists to predict
            // uploads — its verdict must not be able to disagree with them.
            $errors[] = 'upload_url_invalid';
        }

        $this->parseJsonSetting('lcoy-waterfall.extra_params', $errors, 'extra_params_invalid');
        $extraHeaders = $this->parseJsonSetting('lcoy-waterfall.extra_headers', $errors, 'extra_headers_invalid');

        if ($errors) {
            return new JsonResponse(['ok' => false, 'errors' => $errors]);
        }

        $options = [
            'headers' => is_array($extraHeaders) ? $extraHeaders : [],
            'http_errors' => false,
        ];

        $user = (string) $this->settings->get('lcoy-waterfall.basic_auth_user', '');
        $pass = (string) $this->settings->get('lcoy-waterfall.basic_auth_pass', '');

        if ($user !== '') {
            $options['auth'] = [$user, $pass];
        }

        $client = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 3],
        ]);

        try {
            $response = $client->post($url, $options);
            $status = $response->getStatusCode();

            // Read, not cast-to-string: the host is free to answer with
            // anything, redirects are followed, and `(string) $body` would
            // pull the whole of it into memory before the prefix is taken —
            // a large response would take the admin's test click down with it.
            $body = (string) $response->getBody()->read(300);

            return new JsonResponse([
                'ok' => $status < 400,
                'status' => $status,
                'code' => $this->interpretStatus($status),
                'body' => $body,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'errors' => ['connection_error'],
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseJsonSetting(string $key, array &$errors, string $errorKey): ?array
    {
        $raw = trim((string) $this->settings->get($key, ''));

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $errors[] = $errorKey;

            return null;
        }

        return $decoded;
    }

    protected function interpretStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'auth_rejected',
            $status === 403 => 'auth_rejected',
            $status === 400, $status === 422 => 'auth_ok',
            $status === 404 => 'endpoint_not_found',
            $status === 405 => 'endpoint_wrong_method',
            $status >= 200 && $status < 300 => 'ok',
            default => 'unexpected_status',
        };
    }
}
