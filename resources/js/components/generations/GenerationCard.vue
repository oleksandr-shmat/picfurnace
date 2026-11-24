<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Download, ImageOff, X } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationFurnace from '@/components/generations/GenerationFurnace.vue';
import PriceDetails from '@/components/generations/PriceDetails.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { formatUsdRounded } from '@/lib/money';
import type { Generation } from '@/types/generations';

const props = defineProps<{
    generation: Generation;
    imageRetentionDays: number;
}>();

// The picture area keeps the shape of the image, so the page does not jump when it arrives.
const aspectRatio = computed(() => props.generation.size.replace('x', ' / '));

// While it is waiting or being drawn the card shows the furnace; once finished it shows the result.
const making = computed(() => {
    const { status } = props.generation;

    return status === 'pending' ||
        status === 'queued' ||
        status === 'processing'
        ? status
        : null;
});

const placeholder = computed(() => {
    const { status, image } = props.generation;

    if (status === 'pending') {
        return 'Waiting for its turn';
    }

    if (status === 'queued') {
        return 'Waiting at the image service';
    }

    if (status === 'processing') {
        return 'Drawing…';
    }

    if (status === 'succeeded' && image?.url === null) {
        return `The image was deleted after ${props.imageRetentionDays} days.`;
    }

    return 'No image';
});

// The price that was shown before sending, short on the card and exact in the popover.
const estimateRows = computed(() => {
    const { min, expected, max } = props.generation.estimate;

    return [
        { label: 'Estimated, most likely', amount: expected },
        { label: 'Estimated, lowest', amount: min },
        { label: 'Estimated, highest', amount: max },
    ];
});

const estimateRange = computed(() => {
    const { min, max } = props.generation.estimate;

    return `Estimated ${formatUsdRounded(min)} – ${formatUsdRounded(max)}`;
});

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});
const date = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function cancel() {
    router.delete(GenerationController.destroy.url(props.generation.id), {
        preserveScroll: true,
        onError: (errors) => {
            toast.error(
                errors.generation ?? 'This generation cannot be cancelled.',
            );
        },
    });
}
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0">
        <div class="bg-muted" :style="{ aspectRatio }">
            <img
                v-if="generation.image?.url"
                :src="generation.image.url"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
            />
            <GenerationFurnace
                v-else-if="making"
                :status="making"
                :label="placeholder"
            />
            <div
                v-else
                class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff
                    v-if="generation.status === 'failed' || generation.image"
                    class="size-6"
                />
                {{ placeholder }}
            </div>
        </div>

        <CardContent class="space-y-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge
                    :status="generation.status"
                    :cancelled="generation.cancelled"
                />
                <span class="text-xs text-muted-foreground">
                    {{ generation.size }} · {{ generation.quality }}
                </span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p
                v-if="generation.failureMessage && !generation.cancelled"
                class="text-sm text-danger-text"
            >
                {{ generation.failureMessage }}
            </p>

            <div class="space-y-0.5 text-sm tabular-nums">
                <template
                    v-if="
                        generation.status === 'succeeded' && generation.charged
                    "
                >
                    <p class="font-medium">
                        <PriceDetails
                            title="What you were charged"
                            :rows="[
                                {
                                    label: 'Charged',
                                    amount: generation.charged,
                                    strong: true,
                                },
                                ...estimateRows,
                            ]"
                        >
                            Charged {{ formatUsdRounded(generation.charged) }}
                        </PriceDetails>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ estimateRange }}
                    </p>
                </template>
                <p
                    v-else-if="generation.status === 'failed'"
                    class="font-medium"
                >
                    Not charged
                </p>
                <p v-else class="text-muted-foreground">
                    <PriceDetails
                        title="The price that was shown"
                        :rows="estimateRows"
                    >
                        {{ estimateRange }}
                    </PriceDetails>
                </p>
            </div>

            <p class="text-xs text-muted-foreground">
                {{ dateTime.format(new Date(generation.createdAt)) }}
                <template v-if="generation.image?.availableUntil">
                    · Image kept until
                    {{ date.format(new Date(generation.image.availableUntil)) }}
                </template>
            </p>
        </CardContent>

        <CardFooter
            v-if="generation.canCancel || generation.image?.url"
            class="gap-2 px-4 pb-4"
        >
            <Button
                v-if="generation.image?.url"
                as-child
                variant="outline"
                size="sm"
            >
                <a :href="generation.image.url" download>
                    <Download />
                    Download
                </a>
            </Button>
            <Button
                v-if="generation.canCancel"
                variant="outline"
                size="sm"
                @click="cancel"
            >
                <X />
                Cancel
            </Button>
        </CardFooter>
    </Card>
</template>
