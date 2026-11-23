<?php

declare(strict_types=1);

namespace App\Generations;

use App\Billing\Billing;
use App\Enums\GenerationStatus;
use App\Jobs\DispatchGenerationsJob;
use App\Models\Generation;
use App\Money\Usd;
use App\Pixelforge\Api\ProviderGeneration;
use App\Quoting\Markup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The only place where a generation changes status and where a user is charged.
 *
 * Reports from Pixelforge (a webhook, the response to our POST, a poll) are applied through
 * `applyProviderSnapshot`. Each call locks the generation row and only lets the status move
 * forward, so a duplicated or late report changes nothing and a success is charged exactly once.
 */
final class GenerationLifecycle
{
    public const string CANCELLED_BY_USER = 'cancelled_by_user';

    public function __construct(
        private readonly Billing $billing,
    ) {}

    /**
     * Returns true if the report changed the generation, false if it was older than what we know.
     */
    public function applyProviderSnapshot(Generation $generation, ProviderGeneration $snapshot): bool
    {
        return DB::transaction(function () use ($generation, $snapshot) {
            $current = $this->lock($generation);
            $next = $snapshot->status->toGenerationStatus();

            if (! $current->status->canMoveTo($next)) {
                $this->warnIfProviderSucceededWhatWeFailed($current, $next);

                return false;
            }

            if ($current->provider_id !== null && $current->provider_id !== $snapshot->id) {
                throw new LogicException("Generation [{$current->uuid}] is already linked to [{$current->provider_id}], not [{$snapshot->id}].");
            }

            $current->provider_id = $snapshot->id;
            $current->rates = $snapshot->rates->toArray();
            $current->submitted_at ??= now();
            $current->status = $next;

            match ($next) {
                GenerationStatus::Succeeded => $this->recordSuccess($current, $snapshot),
                GenerationStatus::Failed => $this->recordFailure($current, $snapshot->errorCode, $snapshot->errorMessage),
                default => $current->save(),
            };

            // A finished generation frees a slot at Pixelforge: let the next waiting one start now.
            if ($next->isTerminal()) {
                DispatchGenerationsJob::dispatch()->afterCommit();
            }

            return true;
        });
    }

    /**
     * The user cancels a generation that has not been sent to Pixelforge. Free, because nothing
     * exists there. Returns false when it is too late: once the dispatcher has claimed the
     * generation it may already exist at Pixelforge and will be billed if it succeeds.
     */
    public function cancel(Generation $generation): bool
    {
        return DB::transaction(function () use ($generation) {
            $current = $this->lock($generation);

            if ($current->status !== GenerationStatus::Pending || $current->claimed_at !== null) {
                return false;
            }

            $current->status = GenerationStatus::Failed;
            $this->recordFailure($current, self::CANCELLED_BY_USER, 'Cancelled before it was sent.');

            return true;
        });
    }

    /**
     * We give up on a generation that Pixelforge never accepted. Free. Returns false if it is not
     * waiting any more (a report from Pixelforge got there first).
     */
    public function failBeforeSubmission(Generation $generation, string $errorCode, string $errorMessage): bool
    {
        return DB::transaction(function () use ($generation, $errorCode, $errorMessage) {
            $current = $this->lock($generation);

            if ($current->status !== GenerationStatus::Pending) {
                return false;
            }

            $current->status = GenerationStatus::Failed;
            $this->recordFailure($current, $errorCode, $errorMessage);

            return true;
        });
    }

    /**
     * The one case where money is lost: we failed a generation locally (for free), yet Pixelforge
     * completed it and bills us. It needs a human, so it is logged loudly.
     */
    private function warnIfProviderSucceededWhatWeFailed(Generation $current, GenerationStatus $next): void
    {
        if ($current->status === GenerationStatus::Failed && $next === GenerationStatus::Succeeded) {
            Log::critical('Pixelforge completed a generation that we had failed: the user was not charged.', [
                'generation' => $current->uuid,
                'failure_code' => $current->failure_code,
            ]);
        }
    }

    private function lock(Generation $generation): Generation
    {
        return Generation::query()->lockForUpdate()->findOrFail($generation->id);
    }

    private function recordSuccess(Generation $generation, ProviderGeneration $snapshot): void
    {
        // A succeeded snapshot always carries usage and an image: ProviderGeneration refuses it otherwise.
        $providerCost = $snapshot->rates->costOf($snapshot->usage);
        $price = (new Markup($generation->markup_percent))->apply($providerCost);

        $generation->usage = $snapshot->usage->toArray();
        $generation->provider_cost_nano = Usd::toInt($providerCost);
        $generation->charged_nano = Usd::toInt($price);
        $generation->image_url = $snapshot->imageUrl;
        $generation->image_expires_at = $snapshot->imageUrlExpiresAt;
        $generation->completed_at = $snapshot->completedAt ?? now();
        $generation->save();

        $this->billing->settle($generation, $price);
    }

    private function recordFailure(Generation $generation, ?string $errorCode, ?string $errorMessage): void
    {
        $generation->charged_nano = 0;
        $generation->failure_code = $errorCode;
        $generation->failure_message = $errorMessage;
        $generation->completed_at = now();
        $generation->save();

        $this->billing->release($generation);
    }
}
