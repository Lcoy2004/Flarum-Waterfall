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

use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Lcoy\Waterfall\Model\WaterfallImage;

/**
 * Answers "can this user start one more upload right now?" — and, when they
 * cannot, which limit said no. Pure policy: it reads counters and returns a
 * decision, knowing nothing about HTTP, JSON:API error shapes, or the
 * translated phrasing of the refusal. The API layer (UploadImageEndpoint)
 * turns the outcome into the response the contract needs.
 *
 * Two independent limits, both per user:
 *
 *  - an hourly count: the user has uploaded $user_hourly_limit files in the
 *    last hour. Long-window abuse control — it does not care how many are
 *    still in flight.
 *  - a concurrency count: the user has $user_concurrent_uploads images whose
 *    rows still say pending. Short-window resource control — each pending row
 *    is a staged file on disk and a queue delivery on its way, so the limit
 *    is about how much one account may have outstanding, not about how much
 *    they have ever done.
 *
 * Both read the images table (DB counters) rather than a cache so they stay
 * correct across workers and cache stores.
 */
class UploadQuota
{
    // What a refusal points at, as a JSON:API source.pointer suffix (the
    // attribute key the endpoint throws under). These are the contract with
    // the upload modal: it tells a full upload capacity apart from an hourly
    // quota without matching translated text, because the two call for
    // different reactions — a full capacity clears on its own within seconds
    // so the modal waits and sends the file again, while an hourly quota does
    // not. They live next to the policy so a renamed limit cannot drift from
    // the key the client watches for.
    public const POINTER_HOURLY = 'upload_quota';
    public const POINTER_CONCURRENCY = 'upload_capacity';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * The first limit this user's next upload would break, or null when the
     * upload may proceed.
     */
    public function check(User $actor): ?UploadRefusal
    {
        return $this->checkHourly($actor) ?? $this->checkConcurrency($actor);
    }

    protected function checkHourly(User $actor): ?UploadRefusal
    {
        $limit = (int) $this->settings->get('lcoy-waterfall.user_hourly_limit', 20);

        if ($limit <= 0) {
            return null;
        }

        $uploadedLastHour = WaterfallImage::query()
            ->where('user_id', $actor->id)
            ->where('created_at', '>=', (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'))
            ->count();

        if ($uploadedLastHour >= $limit) {
            return new UploadRefusal(
                self::POINTER_HOURLY,
                $this->translator->trans('lcoy-waterfall.api.errors.hourly_limit')
            );
        }

        return null;
    }

    protected function checkConcurrency(User $actor): ?UploadRefusal
    {
        $limit = (int) $this->settings->get('lcoy-waterfall.user_concurrent_uploads', 10);

        if ($limit <= 0) {
            return null;
        }

        $pending = WaterfallImage::query()
            ->where('user_id', $actor->id)
            ->where('status', WaterfallImage::STATUS_PENDING)
            ->count();

        if ($pending >= $limit) {
            return new UploadRefusal(
                self::POINTER_CONCURRENCY,
                $this->translator->trans('lcoy-waterfall.api.errors.concurrency_limit')
            );
        }

        return null;
    }
}
