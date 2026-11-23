<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsd } from '@/lib/money';
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

        <template v-else-if="estimate">
            <p
                class="text-2xl font-semibold tabular-nums transition-opacity"
                :class="{ 'opacity-50': state === 'loading' }"
            >
                {{ formatUsd(estimate.min) }} – {{ formatUsd(estimate.max) }}
            </p>
            <p class="text-sm text-muted-foreground tabular-nums">
                Most likely about {{ formatUsd(estimate.expected) }}
            </p>
        </template>

        <Skeleton v-else class="h-8 w-48" />

        <p class="text-xs text-muted-foreground">
            Includes a {{ markupPercent }}% service fee. The final price is
            based on what the generation actually used, normally within this
            range. A generation that fails is free.
        </p>
    </div>
</template>
