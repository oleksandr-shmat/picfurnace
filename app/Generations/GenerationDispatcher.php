<?php

declare(strict_types=1);

namespace App\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\DispatchGenerationsJob;
use App\Models\Generation;
use App\Pixelforge\Client\PixelforgeClient;
use App\Pixelforge\Client\RequestRejected;
use App\Pixelforge\Client\RetryLater;
use App\Pixelforge\Client\UnexpectedResponse;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Our own queue in front of Pixelforge.
 *
 * Pixelforge allows few concurrent generations and requests per minute for the whole account, so
 * users must not be able to starve each other. Every run sends waiting generations oldest first,
 * at most one per user, while fewer than the allowed number are active at the provider.
 *
 * Only one run works at a time (a cache lock), so two runs can never send the same generation.
 */
final class GenerationDispatcher
{
    private const string LOCK_KEY = 'generation-dispatch';

    // Longer than a run can take: at most a few requests, each with a timeout of 15 seconds.
    private const int LOCK_SECONDS = 180;

    // Attempts that failed for a reason other than a limit (overload, network, unreadable answer).
    private const int MAX_FAILED_ATTEMPTS = 5;

    private const int BACKOFF_BASE_SECONDS = 5;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationLifecycle $lifecycle,
        private readonly DispatchPause $pause,
        private readonly int $maxConcurrent,
    ) {}

    public function dispatchDue(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            foreach ($this->nextToSend() as $generation) {
                // A limit hit by an earlier generation of this run pauses all the others too.
                if ($this->pause->isActive()) {
                    break;
                }

                $this->send($generation);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @return iterable<Generation>
     */
    private function nextToSend(): iterable
    {
        $active = Generation::query()->whereIn('status', $this->activeStatuses())->count();
        $freeSlots = max(0, $this->maxConcurrent - $active);

        return Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            // A user whose generation is already active at Pixelforge waits for it to finish.
            ->whereNotExists(
                fn ($query) => $query->from('generations as active')
                    ->whereColumn('active.user_id', 'generations.user_id')
                    ->whereIn('active.status', $this->activeStatuses())
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->unique('user_id')
            ->take($freeSlots)
            ->values();
    }

    /**
     * @return list<string>
     */
    private function activeStatuses(): array
    {
        return [GenerationStatus::Queued->value, GenerationStatus::Processing->value];
    }

    private function send(Generation $waiting): void
    {
        $generation = $this->claim($waiting);

        if ($generation === null) {
            return;
        }

        try {
            $snapshot = $this->client->createGeneration($generation);
        } catch (RetryLater $e) {
            $this->handleRetryLater($generation, $e);

            return;
        } catch (RequestRejected $e) {
            $this->lifecycle->failBeforeSubmission($generation, $e->errorCode, $e->getMessage());

            return;
        } catch (UnexpectedResponse $e) {
            // A bad key or an unreadable answer. The request may or may not have been accepted, and a
            // retry with the same Idempotency-Key is safe either way.
            report($e);
            $this->backOffOrGiveUp($generation, retryAfterSeconds: 0);

            return;
        }

        $this->lifecycle->applyProviderSnapshot($generation, $snapshot);
    }

    /**
     * Marks the generation as being sent, which stops the user from cancelling it. Returns the
     * up-to-date generation, or null if it was cancelled or finished in the meantime.
     */
    private function claim(Generation $generation): ?Generation
    {
        return DB::transaction(function () use ($generation) {
            $current = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($current->status !== GenerationStatus::Pending) {
                return null;
            }

            $current->claimed_at ??= now();
            $current->save();

            return $current;
        });
    }

    private function handleRetryLater(Generation $generation, RetryLater $e): void
    {
        // Pixelforge rejected the request, so the generation does not exist there and may still be cancelled.
        if ($e->nothingWasCreated) {
            $generation->update(['claimed_at' => null]);
        }

        match ($e->errorCode) {
            // Both limits are shared by the whole account: pause everybody, not only this generation.
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => $this->wakeUpAt($this->pause->forSeconds($e->retryAfterSeconds)),
            'daily_limit_exceeded' => $this->wakeUpAt($this->pause->until(CarbonImmutable::now('UTC')->addDay()->startOfDay())),
            default => $this->backOffOrGiveUp($generation, $e->retryAfterSeconds),
        };
    }

    private function backOffOrGiveUp(Generation $generation, int $retryAfterSeconds): void
    {
        $failedAttempts = $generation->attempts + 1;

        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->lifecycle->failBeforeSubmission(
                $generation,
                'provider_unavailable',
                'The image service is not available right now. You were not charged, please try again later.',
            );

            return;
        }

        // 5, 10, 20, 40 seconds: the fifth failed attempt gives up.
        $backoffSeconds = self::BACKOFF_BASE_SECONDS * 2 ** ($failedAttempts - 1);

        $retryAt = now()->addSeconds(max($backoffSeconds, $retryAfterSeconds));

        $generation->update(['attempts' => $failedAttempts, 'next_attempt_at' => $retryAt]);

        $this->wakeUpAt($retryAt);
    }

    /**
     * Queues a run for the moment something becomes due (a retry, the end of a pause), so that
     * nothing has to be polled for it. The scheduler only catches what gets stuck.
     */
    private function wakeUpAt(CarbonInterface $moment): void
    {
        DispatchGenerationsJob::dispatch()->delay($moment);
    }
}
