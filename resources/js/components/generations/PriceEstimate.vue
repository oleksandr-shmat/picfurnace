<script setup lang="ts">
import PriceDetails from '@/components/generations/PriceDetails.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsdRounded } from '@/lib/money';
import type { PriceRange } from '@/types/generations';

defineProps<{
    estimate: PriceRange | null;
    state: 'loading' | 'ready' | 'unavailable';
    markupPercent: number;
}>();
</script>

<template>
    <div class="space-y-1.5">
        <p class="text-sm font-medium text-muted-foreground">Estimated price</p>

        <template v-if="state === 'unavailable'">
            <p class="text-sm text-muted-foreground">
                The price cannot be calculated right now. Check the form and try
                again in a moment.
            </p>
        </template>

        <PriceDetails
            v-else-if="estimate"
            title="How the price is made up"
            :rows="[
                {
                    label: 'Most likely',
                    amount: estimate.expected,
                    strong: true,
                },
                { label: 'Lowest', amount: estimate.min },
                { label: 'Highest', amount: estimate.max },
            ]"
        >
            <span
                class="text-2xl font-semibold tabular-nums transition-opacity"
                :class="{ 'opacity-50': state === 'loading' }"
            >
                <span class="font-normal text-primary-ink">≈</span>
                {{ formatUsdRounded(estimate.expected) }}
            </span>

            <template #note>
                <p class="text-xs text-muted-foreground">
                    You pay for what the generation actually uses, normally
                    within this range. While it is being made, the highest price
                    is held from your credit and whatever is not used is
                    released.
                </p>
            </template>
        </PriceDetails>

        <Skeleton v-else class="h-8 w-40" />

        <p class="text-xs text-muted-foreground">
            Includes a {{ markupPercent }}% service fee.
        </p>
    </div>
</template>
