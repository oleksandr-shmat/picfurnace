<?php

declare(strict_types=1);

namespace App\Generations;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\ProviderEvent;
use App\Pixelforge\ProviderGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies a verified webhook event.
 *
 * Pixelforge delivers events at least once, in any order, and sometimes before our own request has
 * returned. Recording the event and applying it happen in one transaction: if applying fails the
 * event is not recorded either, so the retry from Pixelforge is processed again instead of being
 * thrown away as a duplicate.
 */
final class WebhookEventHandler
{
    public function __construct(private readonly GenerationLifecycle $lifecycle) {}

    /**
     * @param  array<string, mixed>  $payload  The decoded body, stored as received.
     */
    public function handle(ProviderEvent $event, array $payload): void
    {
        DB::transaction(function () use ($event, $payload) {
            // The unique event id makes this a no-op for a repeated delivery, even a simultaneous one.
            $isFirstDelivery = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $event->id,
                'type' => $event->type,
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            $record = WebhookEvent::query()->where('event_id', $event->id)->firstOrFail();

            if (! $isFirstDelivery) {
                $record->increment('deliveries');

                return;
            }

            $this->apply($event, $record);
        });
    }

    private function apply(ProviderEvent $event, WebhookEvent $record): void
    {
        if ($event->generation === null) {
            $this->finish($record, WebhookOutcome::IgnoredType);

            return;
        }

        $generation = $this->findGeneration($event->generation);

        if ($generation === null) {
            Log::warning('Webhook for a generation we do not know.', ['event' => $event->id, 'provider_id' => $event->generation->id]);
            $this->finish($record, WebhookOutcome::IgnoredUnknownGeneration);

            return;
        }

        $outcome = match ($this->lifecycle->applyProviderSnapshot($generation, $event->generation)) {
            SnapshotOutcome::Applied => WebhookOutcome::Applied,
            SnapshotOutcome::IgnoredStale => WebhookOutcome::IgnoredStale,
        };

        $this->finish($record, $outcome, $generation);
    }

    /**
     * Our uuid in the metadata is the reliable link, it is there even before our POST has returned.
     * The Pixelforge id is the fallback for a generation that has no uuid in its metadata.
     */
    private function findGeneration(ProviderGeneration $snapshot): ?Generation
    {
        if ($snapshot->ourId !== null && Str::isUuid($snapshot->ourId)) {
            $generation = Generation::query()->where('uuid', $snapshot->ourId)->first();

            if ($generation !== null) {
                return $generation;
            }
        }

        return Generation::query()->where('provider_id', $snapshot->id)->first();
    }

    private function finish(WebhookEvent $record, WebhookOutcome $outcome, ?Generation $generation = null): void
    {
        $record->update([
            'generation_id' => $generation?->id,
            'outcome' => $outcome,
            'processed_at' => now(),
        ]);
    }
}
