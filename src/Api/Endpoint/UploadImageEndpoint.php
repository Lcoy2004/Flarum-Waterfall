<?php

/*
 * This file is part of the lcoy/flarum-ext-waterfall extension.
 *
 * Copyright (c) Lcoy.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Lcoy\Waterfall\Api\Endpoint;

use Flarum\Api\Context;
use Flarum\Api\Endpoint\Concerns\IncludesData;
use Flarum\Api\Endpoint\Endpoint;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Lcoy\Waterfall\Jobs\ProcessImageUploadJob;
use Lcoy\Waterfall\Model\WaterfallImage;
use Lcoy\Waterfall\Model\WaterfallSet;
use Lcoy\Waterfall\RateLimit\RateLimiter;
use Lcoy\Waterfall\Upload\UploadValidator;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

use function Tobyz\JsonApiServer\json_api_response;

/**
 * POST /api/waterfall-images — multipart upload endpoint.
 *
 * Implemented as a custom endpoint (shaped like core's Endpoint\Create) instead
 * of Endpoint\Create itself, because the request body is multipart/form-data
 * rather than a JSON:API document: `file` (binary), `title`, `set_id`,
 * `position`, and an optional `thumb` (binary, the browser-encoded card copy).
 *
 * The response is an immediate JSON:API resource with status=pending; the
 * actual image host transfer happens in ProcessImageUploadJob.
 *
 * Per core's Endpoint contract, endpoints() is also invoked on a
 * constructor-less shell instance for route registration, so no injected
 * services may be touched while building the endpoint: services are resolved
 * from the booted container inside the request-time callbacks.
 */
class UploadImageEndpoint extends Endpoint
{
    use IncludesData;

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'create');
    }

    protected function setUp(): void
    {
        $this->route('POST', '/')
            ->action(function (Context $context): ?object {
                $validator = resolve(UploadValidator::class);
                $rateLimiter = resolve(RateLimiter::class);
                $queue = resolve(Queue::class);
                // Resolved here rather than in a constructor: the endpoint is
                // built as a constructor-less shell at route-registration time.
                $translator = resolve(TranslatorInterface::class);

                /** @var User $actor */
                $actor = $context->getActor();

                $file = $context->request->getUploadedFiles()['file'] ?? null;

                if (! $file instanceof UploadedFileInterface) {
                    throw new ValidationException(['file' => $translator->trans('lcoy-waterfall.api.errors.file_missing')]);
                }

                // Real-MIME whitelist and size validation (magic bytes, not extension).
                ['extension' => $extension] = $validator->validate($file);

                // Per-user hourly / concurrent rate limits.
                $rateLimiter->assertUserMayUpload($actor);

                $title = trim((string) ($context->request->getParsedBody()['title'] ?? ''));

                if (mb_strlen($title) > 200) {
                    throw new ValidationException(['title' => $translator->trans('lcoy-waterfall.api.errors.title_too_long')]);
                }

                // The set this image belongs to (created by the uploader before
                // the files are sent) and its dragged-in display position.
                $setId = $this->intOrNull($context->request->getParsedBody()['set_id'] ?? null);
                $position = $this->intOrNull($context->request->getParsedBody()['position'] ?? null) ?? 0;

                $set = null;

                if ($setId !== null) {
                    $set = WaterfallSet::query()->find($setId);

                    // Only the owner (or a moderator) may attach images to a
                    // set; otherwise a crafted set_id would let a user inject
                    // images into someone else's set.
                    if ($set === null
                        || ($set->user_id !== $actor->id && ! $actor->can('lcoy-waterfall.moderate'))) {
                        throw new ValidationException(['set_id' => $translator->trans('lcoy-waterfall.api.errors.set_unavailable')]);
                    }
                }

                // Stage the upload in a non web-accessible spool directory.
                // The bytes must survive until the queue worker picks the job
                // up; the file is deleted right after the transfer (unless the
                // admin enabled local relay).
                $stagedDir = storage_path('waterfall-tmp');

                if (! is_dir($stagedDir)) {
                    @mkdir($stagedDir, 0750, true);
                }

                $stagedPath = $stagedDir.'/'.bin2hex(random_bytes(16)).'.'.$extension;

                // A failed stage (storage unwritable, disk full) must surface
                // as a validation error the uploader can read — the thumbnail
                // below already degrades gracefully, but without this the main
                // file turned the whole request into a raw 500.
                try {
                    $file->moveTo($stagedPath);
                } catch (\Throwable $e) {
                    // Leave a trace for the operator: the translated error
                    // tells the uploader what happened, but the reason (a
                    // permission problem, a full disk) only shows up here —
                    // staging runs before the queue job, so the upload log
                    // never records this failure.
                    resolve(LoggerInterface::class)->error('lcoy-waterfall: staging upload failed: {message}', [
                        'message' => $e->getMessage(),
                    ]);

                    throw new ValidationException([
                        'file' => $translator->trans('lcoy-waterfall.api.errors.staging_failed'),
                    ]);
                }

                // Optional browser-encoded card thumbnail (same multipart
                // request, `thumb` field). A card without one renders the
                // full-size original, so the copy is worth a second host
                // transfer — but a bad or missing thumbnail must never fail
                // the upload itself.
                //
                // Validated as a derived copy rather than an upload: its
                // format is ours to choose (WebP, JPEG as the browser
                // fallback), so the admin's upload whitelist must not reject
                // it.
                $stagedThumbPath = null;
                $thumbFile = $context->request->getUploadedFiles()['thumb'] ?? null;

                if ($thumbFile instanceof UploadedFileInterface) {
                    $thumbPath = null;

                    try {
                        ['extension' => $thumbExtension] = $validator->validateThumbnail($thumbFile);

                        $thumbPath = $stagedDir.'/'.bin2hex(random_bytes(16)).'.'.$thumbExtension;
                        $thumbFile->moveTo($thumbPath);
                        $stagedThumbPath = $thumbPath;
                    } catch (\Throwable) {
                        // A rejected or unreadable thumbnail is simply not
                        // used; a moveTo that failed halfway can still have
                        // created the file, and nothing else prunes the spool.
                        if ($thumbPath !== null) {
                            @unlink($thumbPath);
                        }

                        $stagedThumbPath = null;
                    }
                }

                try {
                    $image = new WaterfallImage();
                    $image->user_id = $actor->id;
                    $image->set_id = $set?->id;
                    $image->position = $position;
                    $image->src = '';
                    $image->thumb = null;
                    $image->title = $title !== '' ? $title : null;
                    $image->status = WaterfallImage::STATUS_PENDING;
                    $image->save();

                    // Reflect the new (pending) image in the set's counters
                    // straight away so the uploader sees the correct count.
                    WaterfallSet::syncAggregatesForImage($image);

                    // ProcessImageUploadJob is the single writer of the upload
                    // log (it records the transfer outcome there). It carries
                    // the image id, not the model, so the job always runs and
                    // can clean up the staged spool file even if the card is
                    // deleted before the worker picks it up.
                    //
                    // The name handed to the image host is built from the
                    // sniffed extension rather than the client's filename: it
                    // travels in the outgoing multipart header, so it must not
                    // carry whatever bytes the uploader chose (a quote or a
                    // newline would forge that header), and the magic-byte
                    // extension is the trustworthy one for the served format.
                    // Only the extension is used — the host renames the file.
                    $queue->push(new ProcessImageUploadJob(
                        $image->id,
                        $stagedPath,
                        'image.'.$extension,
                        $stagedThumbPath
                    ));

                    // The sync queue driver (Flarum's default) runs the job
                    // inline during push(), updating the row directly rather
                    // than this in-memory instance. Reload so the response
                    // reflects the persisted status: published/failed after a
                    // sync run, still pending under an async driver.
                    $image->refresh();
                } catch (\Throwable $e) {
                    // Never leave an orphaned staged file behind when the
                    // model or the queue write fails.
                    @unlink($stagedPath);

                    if ($stagedThumbPath !== null) {
                        @unlink($stagedThumbPath);
                    }

                    throw $e;
                }

                return $image;
            })
            ->beforeSerialization(function (Context $context, object $model) {
                $this->loadRelations(
                    \Flarum\Database\Eloquent\Collection::make([$model]),
                    $context,
                    $this->getInclude($context)
                );
            })
            ->response(function (Context $context, object $model) {
                return json_api_response($this->showResource($context, $model))
                    ->withStatus(201);
            });
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
