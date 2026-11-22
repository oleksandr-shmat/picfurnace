<?php

declare(strict_types=1);

namespace App\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Client\PixelforgeClient;
use App\Pixelforge\Client\RetryLater;
use App\Pixelforge\Client\UnexpectedResponse;

/**
 * Safety net for lost webhooks.
 *
 * Pixelforge does not guarantee webhook delivery. A generation that has been running at Pixelforge
 * with no news for a while is asked about directly, and the answer is applied exactly like a
 * webhook would be. Polling is the exception, not the rule: Pixelforge limits requests for the
 * whole account, so only silent generations are polled, and only a few per run.
 */
final class GenerationReconciler
{
    /** How long a generation may stay silent before we ask Pixelforge about it. */
    private const int SILENT_SECONDS = 180;

    private const int GENERATIONS_PER_RUN = 10;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationLifecycle $lifecycle,
    ) {}

    /**
     * Returns how many generations changed.
     */
    public function reconcile(): int
    {
        $changed = 0;

        foreach ($this->silentGenerations() as $generation) {
            try {
                $snapshot = $this->client->findGeneration($generation->provider_id);
            } catch (RetryLater) {
                // A limit or an outage: the next run tries again, and further requests would only hit it too.
                break;
            } catch (UnexpectedResponse $e) {
                report($e);

                continue;
            }

            if ($this->lifecycle->applyProviderSnapshot($generation, $snapshot)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @return iterable<Generation>
     */
    private function silentGenerations(): iterable
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', now()->subSeconds(self::SILENT_SECONDS))
            ->orderBy('updated_at')
            ->limit(self::GENERATIONS_PER_RUN)
            ->get();
    }
}
