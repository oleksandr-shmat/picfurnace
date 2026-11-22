<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Generations\GenerationReconciler;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile';

    protected $description = 'Ask Pixelforge about generations that have been silent for a while, in case a webhook was lost';

    public function handle(GenerationReconciler $reconciler): int
    {
        $changed = $reconciler->reconcile();

        $this->info("Generations updated: {$changed}");

        return self::SUCCESS;
    }
}
