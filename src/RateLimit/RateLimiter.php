<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\RateLimit;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Lcoy\Waterfall\Model\WaterfallImage;
use Psr\Log\LoggerInterface;

/**
 * Upload rate limiting, fully configurable from the admin panel.
 *
 * - Per-user hourly upload count and concurrent pending uploads are enforced
 *   at API time (DB counters — correct across workers and cache stores).
 * - The site-wide per-minute transfer rate is enforced inside the queue job:
 *   over-quota jobs are released back onto the queue with a delay instead of
 *   being dropped, and every deferral is written to the upload log.
 */
class RateLimiter
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CacheRepository $cache,
        protected TranslatorInterface $translator,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Enforce per-user limits at request time.
     *
     * The keys below are not form fields: Flarum turns each one into
     * `source.pointer` in the JSON:API error document, which is how the upload
     * modal tells the two refusals apart without matching translated text. It
     * matters because they call for different reactions — a full upload
     * capacity clears on its own within seconds, so the modal waits and sends
     * the file again, while an hourly quota does not.
     *
     * @throws ValidationException
     */
    public function assertUserMayUpload(User $actor): void
    {
        $hourlyLimit = (int) $this->settings->get('lcoy-waterfall.user_hourly_limit', 20);

        if ($hourlyLimit > 0) {
            $uploadedLastHour = WaterfallImage::query()
                ->where('user_id', $actor->id)
                ->where('created_at', '>=', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'))
                ->count();

            if ($uploadedLastHour >= $hourlyLimit) {
                throw new ValidationException([
                    'upload_quota' => $this->translator->trans('lcoy-waterfall.api.errors.hourly_limit'),
                ]);
            }
        }

        $concurrentLimit = (int) $this->settings->get('lcoy-waterfall.user_concurrent_uploads', 3);

        if ($concurrentLimit > 0) {
            $pending = WaterfallImage::query()
                ->where('user_id', $actor->id)
                ->where('status', WaterfallImage::STATUS_PENDING)
                ->count();

            if ($pending >= $concurrentLimit) {
                throw new ValidationException([
                    'upload_capacity' => $this->translator->trans('lcoy-waterfall.api.errors.concurrency_limit'),
                ]);
            }
        }
    }

    /**
     * Atomically reserve one transfer slot for the current minute.
     *
     * Called by the queue job immediately before talking to the image host.
     * Returns true when the transfer may proceed, false when the site-wide
     * per-minute quota is exhausted (the job should then release() itself).
     */
    public function reserveTransferSlot(): bool
    {
        $limit = (int) $this->settings->get('lcoy-waterfall.global_per_minute_limit', 60);

        if ($limit <= 0) {
            return true;
        }

        $key = 'lcoy-waterfall.transfers.'.date('YmdHi');

        // Atomic counter across workers: add() only seeds the bucket when it
        // does not exist yet, and increment() maps to Redis INCR (and to
        // lock-guarded read-modify-write on the file/database stores), so
        // parallel jobs can never read the same count and overshoot the quota.
        // 120s TTL: long enough to cover the minute bucket, short enough to
        // self-clean on cache stores without TTL support.
        $this->cache->add($key, 0, 120);
        $count = (int) $this->cache->increment($key);

        if ($count > $limit) {
            $this->logger->info('lcoy-waterfall: global transfer quota exceeded for this minute, deferring job', [
                'count' => $count,
                'limit' => $limit,
            ]);

            return false;
        }

        return true;
    }
}
