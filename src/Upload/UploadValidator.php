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

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Validates an uploaded image against the admin-configured MIME whitelist and
 * size limit. The real MIME type is sniffed from the file's magic bytes, never
 * trusted from the client-provided metadata or extension.
 */
class UploadValidator
{
    // Canonicalisation map: legacy/alias sniffed MIME types -> the standard
    // type they are equivalent to.
    protected const MIME_ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-png' => 'image/png',
    ];

    // The only formats the browser-side encoder can emit: makeThumb() asks the
    // canvas for WebP first and falls back to JPEG, and keeps a blob only when
    // the browser honoured the requested type (a canvas that cannot encode
    // WebP hands back a PNG, which is discarded rather than uploaded).
    protected const THUMBNAIL_EXTENSIONS = ['webp', 'jpg', 'jpeg'];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * @return array{mime: string, extension: string} the sniffed MIME type and
     *         a safe file extension derived from it
     *
     * @throws ValidationException
     */
    public function validate(UploadedFileInterface $file): array
    {
        return $this->validateAgainst($file, $this->whitelist());
    }

    /**
     * Validate the browser-generated card copy.
     *
     * Its format is chosen by the extension rather than the uploader —
     * makeThumb() asks the canvas for WebP and keeps a JPEG only when the
     * browser cannot encode WebP — so the admin's upload whitelist must not
     * veto it. A site that accepts only PNG uploads still wants card copies,
     * and dropping them silently would quietly put the full-size original
     * back on every card with nothing to show that anything went wrong. Size
     * and real MIME sniffing are enforced exactly as for an upload.
     *
     * @return array{mime: string, extension: string}
     *
     * @throws ValidationException
     */
    public function validateThumbnail(UploadedFileInterface $file): array
    {
        return $this->validateAgainst($file, self::THUMBNAIL_EXTENSIONS);
    }

    /**
     * @param string[] $allowed the extensions the caller is willing to accept
     *
     * @return array{mime: string, extension: string}
     *
     * @throws ValidationException
     */
    protected function validateAgainst(UploadedFileInterface $file, array $allowed): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => $this->translator->trans('lcoy-waterfall.api.errors.upload_error')]);
        }

        $maxSizeMb = (int) $this->settings->get('lcoy-waterfall.max_size_mb', 10);

        // getSize() is nullable per the PSR-7 uploaded-file contract; when the
        // size cannot be determined, fail closed rather than skipping the cap.
        $size = $file->getSize() ?? PHP_INT_MAX;

        if ($size > $maxSizeMb * 1024 * 1024) {
            throw new ValidationException([
                'file' => $this->translator->trans('lcoy-waterfall.api.errors.file_too_large', ['{max}' => $maxSizeMb]),
            ]);
        }

        // Sniff the real MIME type from magic bytes at the beginning of the
        // temp stream, ignoring the client-supplied Content-Type entirely.
        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(4096);
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        if ($sniffed === false || $sniffed === '') {
            throw new ValidationException(['file' => $this->translator->trans('lcoy-waterfall.api.errors.file_unreadable')]);
        }

        $extension = $this->extensionForMime($sniffed, $allowed);

        if ($extension === null) {
            throw new ValidationException(['file' => $this->translator->trans('lcoy-waterfall.api.errors.mime_not_allowed')]);
        }

        return ['mime' => $sniffed, 'extension' => $extension];
    }

    /**
     * Map a sniffed MIME type to an accepted extension, or null when the type
     * is not allowed. "jpg" and "jpeg" whitelist entries are treated as
     * equivalent.
     *
     * @param string[] $whitelist
     */
    protected function extensionForMime(string $mime, array $whitelist): ?string
    {
        $mime = self::MIME_ALIASES[$mime] ?? $mime;

        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $extension = substr($mime, strlen('image/'));

        if (in_array($extension, $whitelist, true)) {
            return $extension;
        }

        if ($extension === 'jpeg' && in_array('jpg', $whitelist, true)) {
            return 'jpg';
        }

        if ($extension === 'jpg' && in_array('jpeg', $whitelist, true)) {
            return 'jpeg';
        }

        return null;
    }

    /**
     * The configured MIME whitelist as a list of extensions.
     *
     * @return string[]
     */
    public function whitelist(): array
    {
        $raw = (string) $this->settings->get('lcoy-waterfall.mime_whitelist', 'jpg,jpeg,png,gif,webp');

        return array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
    }
}
