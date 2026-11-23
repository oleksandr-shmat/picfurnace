<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_image_urls
 * @property int $markup_percent
 * @property int $estimated_price_min_nano
 * @property int $estimated_price_expected_nano
 * @property int $estimated_price_max_nano
 * @property string|null $provider_id
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int|null $provider_cost_nano
 * @property int|null $charged_nano
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_expires_at
 * @property string|null $image_path
 * @property CarbonImmutable|null $image_stored_at
 * @property CarbonImmutable|null $image_purged_at
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    // Created and changed only by our own services, never from request input.
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_image_urls' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_expires_at' => 'datetime',
            'image_stored_at' => 'datetime',
            'image_purged_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        // A deleted user keeps their history, so the owner is looked up including soft deleted ones.
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
