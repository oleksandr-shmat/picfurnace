<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Generations\GenerationDispatcher;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the waiting generations to Pixelforge.
 *
 * It is queued whenever there is something to send or something becomes due: when a generation is
 * submitted, when one finishes and frees a slot at Pixelforge, and, delayed, for the time of a
 * retry or the end of a pause. All the rules of what may be sent now are in GenerationDispatcher.
 *
 * At most one run is queued for each moment: ten submissions in the same second queue one run, and
 * runs for different moments never keep each other out. The scheduler runs the same work every
 * minute as a watchdog for whatever gets stuck (a lost job, a worker that was down).
 */
final class DispatchGenerationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Longer than a run can take: a few requests with a timeout of 15 seconds each.
    public int $timeout = 120;

    // A lost job must not keep out the next run for the same moment for ever.
    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return $this->delay instanceof DateTimeInterface ? (string) $this->delay->getTimestamp() : 'now';
    }

    public function handle(GenerationDispatcher $dispatcher): void
    {
        $dispatcher->dispatchDue();
    }
}
