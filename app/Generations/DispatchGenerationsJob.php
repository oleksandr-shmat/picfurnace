<?php

declare(strict_types=1);

namespace App\Generations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the waiting generations to Pixelforge right away.
 *
 * It is queued when a generation is submitted and when one finishes (which frees a slot at
 * Pixelforge). The scheduler runs the same work every minute as a safety net, so nothing waits
 * for long even if no worker is running. All the rules of what may be sent now are in
 * GenerationDispatcher.
 *
 * Only one of these waits in the queue at a time; one that arrives while a run is under way is
 * queued again, so a generation saved during a run is not missed.
 */
final class DispatchGenerationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Longer than a run can take: a few requests with a timeout of 15 seconds each.
    public int $timeout = 120;

    public function handle(GenerationDispatcher $dispatcher): void
    {
        $dispatcher->dispatchDue();
    }
}
