<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Generations\GenerationDispatcher;
use Illuminate\Console\Command;

class DispatchGenerations extends Command
{
    protected $signature = 'generations:dispatch';

    protected $description = 'Send waiting generations to Pixelforge, respecting its limits and fairness between users';

    public function handle(GenerationDispatcher $dispatcher): int
    {
        $dispatcher->dispatchDue();

        return self::SUCCESS;
    }
}
