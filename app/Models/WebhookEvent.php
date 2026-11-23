<?php

declare(strict_types=1);

namespace App\Models;

use App\Generations\Webhooks\WebhookOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property int|null $generation_id
 * @property array<string, mixed> $payload
 * @property int $deliveries
 * @property WebhookOutcome|null $outcome
 * @property CarbonImmutable|null $processed_at
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
