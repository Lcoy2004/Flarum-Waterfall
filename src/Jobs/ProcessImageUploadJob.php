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
use Illuminate\Queue\Jobs\SyncJob;
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
    // Upload log rows older than this are pruned after every successful
    // transfer, so the audit trail stays bounded.
    protected const LOG_RETENTION_DAYS = 3;

    /**
     * How many deliveries this job may take part in.
     *
     * Flarum's worker runs with a single try by default, and a job released
     * past its tries is failed *before* handle() is reached on the next
     * delivery. Declaring the budget is what lets the quota deferral (see
     * canBeDeferred()) come back at all — with the default of one try it
     * could only end in a job that never runs again.
     */
    public int $tries = 3;

    /**
     * How long one delivery's transfers may take in total, in seconds.
     *
     * Flarum builds its database queue with a hardcoded retry_after of 60
     * seconds: a job still running after that is read as abandoned and handed
     * to a second worker, which then sends the same bytes to the host again
     * while the first run is still going — two copies at the host, two log
     * rows, and a race over the image row. The budget keeps both transfers,
     * every attempt and the backoff between them inside that window, with
     * room to spare for the row writes and the retention prune that follow.
     */
    protected const TRANSFER_BUDGET_SECONDS = 45;

    /**
     * The smallest slice of the budget worth starting a thumbnail transfer
     * with. The card copy is optional by design — the feed falls back to the
     * full image — so once the original has spent the budget the thumbnail is
     * dropped rather than allowed to push the delivery past its deadline.
     */
    protected const THUMBNAIL_MIN_BUDGET_SECONDS = 8;

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
            // A delivery is not necessarily the first: an unexpected error
            // re-throws, and with a tries budget above one the job comes back.
            // By then the staged files are gone either way — the first run
            // either deleted them after a successful transfer or freed them
            // while failing. Anything already resolved is therefore done, and
            // marking it failed again would strand a copy the host is already
            // serving.
            if ($image->status !== WaterfallImage::STATUS_PENDING) {
                if ($stagedThumbPath !== null) {
                    @unlink($stagedThumbPath);
                }

                return;
            }

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

        // Site-wide per-minute quota. Over-quota jobs are put back on the
        // queue with a delay when the queue can actually bring them back, and
        // failed honestly when it cannot (see canBeDeferred()).
        //
        // What must never happen is the job ending without either: a pending
        // row counts against the uploader's concurrency allowance
        // (RateLimiter::assertUserMayUpload), so a dropped job would lock them
        // out of uploading until they deleted the card by hand, and the staged
        // file would sit in the spool forever.
        if (! $rateLimiter->reserveTransferSlot()) {
            $deferrable = $this->canBeDeferred();

            WaterfallUploadLog::query()->create([
                'image_id' => $image->id,
                'user_id' => $image->user_id,
                'status' => $deferrable ? WaterfallUploadLog::STATUS_DEFERRED : WaterfallUploadLog::STATUS_FAILED,
                'error' => $deferrable
                    ? 'Site-wide per-minute transfer quota exceeded; job delayed.'
                    : 'Site-wide per-minute transfer quota exceeded, and this queue cannot run the job again.',
            ]);

            if ($deferrable) {
                // Re-attempt at the start of the next minute bucket.
                $this->release(max(5, 60 - (int) date('s')));

                return;
            }

            $this->markFailed($image, 'transfer_quota_exceeded', 'The site is at its upload limit right now. Please try again in a minute.', $logger, $events);
            $this->cleanupStagedFile($settings, $stagedThumbPath);

            return;
        }

        $log = WaterfallUploadLog::query()->create([
            'image_id' => $image->id,
            'user_id' => $image->user_id,
            'status' => WaterfallUploadLog::STATUS_PENDING,
        ]);

        // Whether the bytes reached the host and the row went live. Everything
        // after that point is bookkeeping, and must not be able to turn a
        // published image back into a failed one (see the catch below).
        $published = false;

        // Everything from here to the end of the transfers has to fit inside
        // Flarum's retry_after (see TRANSFER_BUDGET_SECONDS).
        $deadline = microtime(true) + self::TRANSFER_BUDGET_SECONDS;

        try {
            $result = $uploader->upload($this->stagedPath, $this->filename, $this->remainingBudget($deadline));

            // The card copy (when the browser sent one) goes to the host
            // before the row is written, so the publish stays a single write.
            // A failed thumbnail transfer only costs the card some bytes: the
            // feed falls back to the full image, never to a broken card.
            //
            // The host's own thumbnail stays as a middle fallback: hosts that
            // return one keep working exactly as they did before browsers
            // started sending a copy.
            $thumbSrc = $this->transferThumbnail($uploader, $rateLimiter, $logger, $stagedThumbPath, $deadline)
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

            $published = true;

            // Publishing changes the set's cover/status/counters.
            WaterfallSet::syncAggregatesForImage($image);

            $log->forceFill([
                'status' => WaterfallUploadLog::STATUS_SUCCESS,
                'http_code' => $result->httpCode,
                'duration_ms' => $result->durationMs,
                'attempts' => $result->attempts,
            ])->save();

            $this->cleanupStagedFile($settings, $stagedThumbPath);
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
            if ($published) {
                // The copy is already on the host and the row says published;
                // what threw here is the aggregate sync or the log write. The
                // uploader has just been shown a live image, and marking it
                // failed would contradict both the host's copy and the SUCCESS
                // row the upload log is meant to hold — so the row is left
                // alone, the spool is still freed, and the exception is
                // re-thrown so the job lands in failed_jobs for investigation.
                $logger->error('lcoy-waterfall: image {id} was published but post-transfer bookkeeping failed: {message}', [
                    'id' => $image->id,
                    'message' => $e->getMessage(),
                ]);

                $this->cleanupStagedFile($settings, $stagedThumbPath);

                throw $e;
            }

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

        // The retention prune runs on every successful transfer rather than on
        // a random sample of them: the DELETE is an indexed range over
        // created_at, so it costs almost nothing when there is nothing to
        // drop, and a sampled prune is a promise the log may never keep.
        //
        // Both calls sit outside the try — neither is part of the transfer,
        // and neither failing may un-publish an image that is already live.
        $this->pruneOldLogs();

        $events->dispatch(new ImageWasUploaded($image, $image->user));
    }

    /**
     * Whether the queue can run this job again after a release().
     *
     * Two cases where it cannot — and where deferring would therefore leave
     * the image pending forever, holding a concurrency slot with its spool
     * file still on disk:
     *
     *  - the sync driver (Flarum's default, when no queue is configured) runs
     *    the job inline, so release() only flags the run as released and it
     *    ends right there;
     *  - once the deliveries reach $tries, the worker fails the job *before*
     *    handle() is reached, so the run that released it was the last one.
     *
     * A null $job means the job was dispatched to run now rather than through
     * a queue, where there is nothing to release back onto either.
     */
    protected function canBeDeferred(): bool
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            return false;
        }

        return $this->attempts() < $this->tries;
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
     * exhausted for this minute, the delivery's time budget is spent, or the
     * transfer failed for any reason — the caller then falls back to the
     * full-size src.
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
        ?string $stagedThumbPath,
        float $deadline
    ): ?string {
        if ($stagedThumbPath === null) {
            return null;
        }

        $remaining = $this->remainingBudget($deadline);

        if ($remaining < self::THUMBNAIL_MIN_BUDGET_SECONDS) {
            $logger->info('lcoy-waterfall: thumbnail for image {id} skipped, the transfer budget is spent; the card will use the full image', [
                'id' => $this->imageId,
            ]);

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
            return $uploader->upload($stagedThumbPath, 'thumb.'.pathinfo($stagedThumbPath, PATHINFO_EXTENSION), $remaining)->src;
        } catch (\Throwable $e) {
            $logger->warning('lcoy-waterfall: thumbnail transfer for image {id} failed: {message}; the card will use the full image', [
                'id' => $this->imageId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whole seconds left before the delivery's deadline, never negative.
     */
    protected function remainingBudget(float $deadline): int
    {
        return (int) max(0, floor($deadline - microtime(true)));
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
