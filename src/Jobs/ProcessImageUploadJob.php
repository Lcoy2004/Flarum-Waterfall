<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Jobs;

use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Lcoy\Waterfall\Event\ImageUploadFailed;
use Lcoy\Waterfall\Event\ImageWasUploaded;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\Model\WaterfallUploadLog;
use Lcoy\Waterfall\RateLimit\RateLimiter;
use Lcoy\Waterfall\Recommend\ScoreCalculatorInterface;
use Lcoy\Waterfall\Upload\Exception\UploadException;
use Lcoy\Waterfall\Upload\ExternalImageHostUploader;
use Psr\Log\LoggerInterface;

/**
 * Asynchronously transfers a staged upload to the external image host.
 *
 * The uploaded bytes are staged in storage/waterfall-tmp (a non web-accessible
 * queue spool, deleted right after the transfer) because the queue worker runs
 * outside the HTTP request. This is not "local hosting": the file never enters
 * public storage. When the admin enables "local relay", the staged copy is
 * retained under storage/waterfall-relay for audit instead of being deleted.
 */
class ProcessImageUploadJob extends AbstractJob
{
    // Upload log rows older than this are pruned opportunistically after
    // each transfer, so the audit trail stays bounded.
    protected const LOG_RETENTION_DAYS = 3;

    public function __construct(
        protected int $imageId,
        protected string $stagedPath,
        protected string $filename,
        protected ?string $stagedThumbPath = null
    ) {
        parent::__construct();
    }

    public function handle(
        ExternalImageHostUploader $uploader,
        RateLimiter $rateLimiter,
        SettingsRepositoryInterface $settings,
        LoggerInterface $logger,
        Dispatcher $events,
        ScoreCalculatorInterface $calculator
    ): void {
        // Carry the image id rather than the model: AbstractJob's
        // deleteWhenMissingModels drops a job whose serialized model is gone
        // before the worker runs it, which would skip this method entirely and
        // leak the staged file. Resolving by id keeps handle() running, so a
        // card deleted while still pending still frees its spool file.
        $image = WaterfallImage::query()->find($this->imageId);

        // ?? is isset-based, so jobs queued before the thumbnail field
        // existed (whose unserialized payloads lack the property) read as
        // null instead of erroring on the uninitialized typed property.
        $stagedThumbPath = $this->stagedThumbPath ?? null;

        if ($image === null) {
            $logger->info('lcoy-waterfall: image {id} was deleted before its transfer ran, discarding staged file', [
                'id' => $this->imageId,
            ]);

            @unlink($this->stagedPath);

            if ($stagedThumbPath !== null) {
                @unlink($stagedThumbPath);
            }

            return;
        }

        if (! file_exists($this->stagedPath)) {
            WaterfallUploadLog::query()->create([
                'image_id' => $image->id,
                'user_id' => $image->user_id,
                'status' => WaterfallUploadLog::STATUS_FAILED,
                'error' => 'The staged upload file could not be found.',
            ]);

            if ($stagedThumbPath !== null) {
                @unlink($stagedThumbPath);
            }

            $this->markFailed($image, 'staged_file_missing', 'The staged upload file could not be found.', $logger, $events);

            return;
        }

        // Site-wide per-minute quota: over-quota jobs go back onto the queue
        // with a delay (never dropped) and the deferral is logged.
        if (! $rateLimiter->reserveTransferSlot()) {
            WaterfallUploadLog::query()->create([
                'image_id' => $image->id,
                'user_id' => $image->user_id,
                'status' => WaterfallUploadLog::STATUS_DEFERRED,
                'error' => 'Site-wide per-minute transfer quota exceeded; job delayed.',
            ]);

            // Re-attempt at the start of the next minute bucket.
            $this->release(max(5, 60 - (int) date('s')));

            return;
        }

        $log = WaterfallUploadLog::query()->create([
            'image_id' => $image->id,
            'user_id' => $image->user_id,
            'status' => WaterfallUploadLog::STATUS_PENDING,
        ]);

        try {
            $result = $uploader->upload($this->stagedPath, $this->filename);

            // The card copy (when the browser sent one) goes to the host
            // before the row is written, so the publish stays a single write.
            // A failed thumbnail transfer only costs the card some bytes: the
            // feed falls back to the full image, never to a broken card.
            //
            // The host's own thumbnail stays as a middle fallback: hosts that
            // return one keep working exactly as they did before browsers
            // started sending a copy.
            $thumbSrc = $this->transferThumbnail($uploader, $rateLimiter, $logger, $stagedThumbPath)
                ?? $result->thumb
                ?? $result->src;

            // Publish and score in a single write: the score is part of the
            // same row, and recalculating it in a separate job would leave the
            // set aggregate to be recomputed a second time (the sync below
            // already covers it).
            $image->forceFill([
                'src' => $result->src,
                'thumb' => $thumbSrc,
                'status' => WaterfallImage::STATUS_PUBLISHED,
                'error' => null,
                'score' => $calculator->calculate($image),
            ])->save();

            // Publishing changes the set's cover/status/counters.
            WaterfallSet::syncAggregatesForImage($image);

            $log->forceFill([
                'status' => WaterfallUploadLog::STATUS_SUCCESS,
                'http_code' => $result->httpCode,
                'duration_ms' => $result->durationMs,
                'attempts' => $result->attempts,
            ])->save();

            $this->cleanupStagedFile($settings, $stagedThumbPath);

            // Retention pruning runs on ~5% of successful transfers: the
            // indexed range DELETE is cheap, but there is no reason to pay it
            // on every single upload — the 3-day window makes a delayed prune
            // invisible.
            if (random_int(1, 20) === 1) {
                $this->pruneOldLogs();
            }

            $events->dispatch(new ImageWasUploaded($image, $image->user));
        } catch (UploadException $e) {
            $log->forceFill([
                'status' => WaterfallUploadLog::STATUS_FAILED,
                'http_code' => $e->httpCode,
                'duration_ms' => $e->durationMs,
                'attempts' => $e->attempts,
                'error' => $e->getMessage(),
            ])->save();

            $this->markFailed($image, $e->errorCode, $e->getMessage(), $logger, $events);

            $this->cleanupStagedFile($settings, $stagedThumbPath);
        } catch (\Throwable $e) {
            // Unexpected failure (bug, storage/DB error, fatal in the upload
            // stack, ...). Still mark the image failed and free the spool file
            // so it cannot stay pending forever, then re-throw so the job is
            // recorded in failed_jobs for investigation.
            $log->forceFill([
                'status' => WaterfallUploadLog::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            $this->markFailed($image, 'unexpected_error', $e->getMessage(), $logger, $events);

            $this->cleanupStagedFile($settings, $stagedThumbPath);

            throw $e;
        }
    }

    /**
     * Keep the audit trail bounded: drop rows older than LOG_RETENTION_DAYS.
     */
    protected function pruneOldLogs(): void
    {
        WaterfallUploadLog::query()
            ->where('created_at', '<', (new \DateTimeImmutable('-'.self::LOG_RETENTION_DAYS.' days'))->format('Y-m-d H:i:s'))
            ->delete();
    }

    /**
     * Forward the staged card thumbnail to the image host and return its src,
     * or null when there is no thumbnail, the site-wide transfer quota is
     * exhausted for this minute, or the transfer failed for any reason —
     * the caller then falls back to the full-size src.
     *
     * The transfer is counted against the same per-minute quota as the
     * original: it is a real host request. A quota miss is not deferred (the
     * original is already up, and re-running the whole job for a thumbnail
     * would double-transfer it).
     */
    protected function transferThumbnail(
        ExternalImageHostUploader $uploader,
        RateLimiter $rateLimiter,
        LoggerInterface $logger,
        ?string $stagedThumbPath
    ): ?string {
        if ($stagedThumbPath === null) {
            return null;
        }

        try {
            if (! $rateLimiter->reserveTransferSlot()) {
                $logger->info('lcoy-waterfall: thumbnail for image {id} skipped, per-minute transfer quota exhausted; the card will use the full image', [
                    'id' => $this->imageId,
                ]);

                return null;
            }

            // Named for what the bytes actually are, not for the original's
            // name. The host picks the format it stores (and serves) from the
            // extension of the filename it is handed — measured: the same WebP
            // sent as "thumb_x.jpg" comes back as JPEG both in its header and
            // in its Content-Type. Reusing the original's name therefore threw
            // the browser's WebP away through a second lossy encode and handed
            // the card a bigger file. The staged path carries the sniffed
            // extension, so this is the real format.
            return $uploader->upload($stagedThumbPath, 'thumb.'.pathinfo($stagedThumbPath, PATHINFO_EXTENSION))->src;
        } catch (\Throwable $e) {
            $logger->warning('lcoy-waterfall: thumbnail transfer for image {id} failed: {message}; the card will use the full image', [
                'id' => $this->imageId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function markFailed(
        WaterfallImage $image,
        string $errorCode,
        string $message,
        LoggerInterface $logger,
        Dispatcher $events
    ): void {
        $image->forceFill([
            'status' => WaterfallImage::STATUS_FAILED,
            'error' => $message,
        ])->save();

        // A failed image can change the set's cover/status/counters.
        WaterfallSet::syncAggregatesForImage($image);

        // Structured entry in the Flarum log for server-side troubleshooting.
        $logger->error('lcoy-waterfall: image upload {id} failed ({code}): {message}', [
            'id' => $image->id,
            'code' => $errorCode,
            'message' => $message,
        ]);

        $events->dispatch(new ImageUploadFailed($image, $image->user, $errorCode, $message));
    }

    /**
     * Free the spool files. The thumbnail is derived data, so it is always
     * deleted — it never enters the relay archive even when the admin keeps
     * a copy of the original for audit.
     */
    protected function cleanupStagedFile(SettingsRepositoryInterface $settings, ?string $stagedThumbPath): void
    {
        $keepRelayCopy = (bool) $settings->get('lcoy-waterfall.local_relay', false);

        if ($keepRelayCopy) {
            $relayDir = storage_path('waterfall-relay');

            if (! is_dir($relayDir)) {
                @mkdir($relayDir, 0750, true);
            }

            @rename($this->stagedPath, $relayDir.'/'.basename($this->stagedPath));
        } else {
            @unlink($this->stagedPath);
        }

        if ($stagedThumbPath !== null) {
            @unlink($stagedThumbPath);
        }
    }
}
