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

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * The site-wide per-minute transfer budget for the image host.
 *
 * A single Redis counter is incremented for every transfer — the original
 * upload and the optional card copy each count — and compared against
 * `global_per_minute_limit`. Enforcement lives inside the queue job (not the
 * API request) because the budget is spent at transfer time, which happens
 * asynchronously after the response has gone back. Over-quota jobs release()
 * themselves back onto the queue for the next minute bucket instead of being
 * dropped, and every deferral is written to the upload log.
 */
class RateLimiter
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected CacheRepository $cache,
        protected LoggerInterface $logger
    ) {
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

        try {
            // add() only seeds the bucket when it does not exist yet, and
            // increment() is atomic on the stores that matter: Redis INCR, and
            // a transaction with a row lock on the database store. The file
            // store is the exception — its increment() is a plain
            // read-modify-write, so two workers racing there can lose a count
            // and let the minute run slightly over. That is an argument for not
            // running a busy site on the file store, not for changing the check.
            //
            // 120s TTL: long enough to cover the minute bucket, short enough to
            // self-clean on cache stores without TTL support.
            $this->cache->add($key, 0, 120);
            $count = (int) $this->cache->increment($key);
        } catch (\Throwable $e) {
            // The quota exists to keep a burst off the image host; it is not
            // what makes a transfer valid. Failing here would be far worse than
            // the burst it prevents: the job would spend its tries and land in
            // failed_jobs with the row still pending and its staged file still
            // on disk, and a pending row holds one of the uploader's
            // concurrency slots — so a cache blip would lock people out of
            // uploading until someone deleted the cards by hand.
            $this->logger->warning('lcoy-waterfall: transfer quota could not be checked ({message}); letting the transfer through', [
                'message' => $e->getMessage(),
            ]);

            return true;
        }

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
