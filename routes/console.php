<?php

use Illuminate\Support\Facades\Schedule;

// Waiting generations are sent by DispatchGenerationsJob: at once, and delayed for retries and the
// end of a pause. This is the watchdog for whatever gets stuck, such as a lost job or a worker that
// was down; it also keeps the app working without a queue worker.
Schedule::command('generations:dispatch')->everyMinute()->withoutOverlapping();

Schedule::command('generations:archive-images')->everyMinute()->withoutOverlapping();

Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();
