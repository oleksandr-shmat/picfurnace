<?php

declare(strict_types=1);

namespace App\Models;

use App\Generations\WebhookOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property int|null $generation_id
 * @property array<string, mixed> $payload
 * @property int $deliveries
 * @property WebhookOutcome|null $outcome
 * @property Carbon|null $processed_at
 */
class WebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'outcome' => WebhookOutcome::class,
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
