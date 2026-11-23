<?php

declare(strict_types=1);

/*
 * Runs one GenerationLifecycle call in a process of its own, for the concurrency tests
 * (tests/Concurrency). Only a second process has a second database connection, which is
 * what it takes to see a row lock work.
 *
 * Usage: php lifecycle_in_child_process.php snapshot <generation id> <fixture name>
 *        php lifecycle_in_child_process.php cancel   <generation id>
 *
 * Prints {"changed": true|false}: whether the call changed the generation.
 */

use App\Generations\GenerationLifecycle;
use App\Models\Generation;
use App\Pixelforge\Api\ProviderGeneration;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $generationId, $fixture] = $argv + [null, null, null, null];

$generation = Generation::query()->findOrFail((int) $generationId);
$lifecycle = $app->make(GenerationLifecycle::class);

if ($action === 'snapshot') {
    $data = json_decode(file_get_contents(__DIR__."/../Fixtures/pixelforge/{$fixture}.json"), true, flags: JSON_THROW_ON_ERROR);
    $changed = $lifecycle->applyProviderSnapshot($generation, ProviderGeneration::fromArray($data['data']['object'] ?? $data));
} elseif ($action === 'cancel') {
    $changed = $lifecycle->cancel($generation);
} else {
    fwrite(STDERR, "Unknown action [{$action}].\n");
    exit(2);
}

echo json_encode(['changed' => $changed]);
