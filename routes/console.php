<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Waiting generations are normally sent at once by DispatchGenerationsJob. This is the safety net:
// it also picks up retries that are due and runs when no queue worker is running.
Schedule::command('generations:dispatch')->everyMinute()->withoutOverlapping();

Schedule::command('generations:archive-images')->everyMinute()->withoutOverlapping();

Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();
