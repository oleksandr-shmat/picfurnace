<script setup lang="ts">
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import Sparkline from '@/components/admin/Sparkline.vue';

const props = withDefaults(
    defineProps<{
        title: string;
        /** A signed percentage against the period before ("+12.5"), or null when there is none. */
        change?: string | null;
        /** A rise is good news, except for a cost. */
        goodWhenUp?: boolean;
        series?: number[];
    }>(),
    { change: null, goodWhenUp: true, series: undefined },
);

const direction = computed(() => {
    if (props.change === null) {
        return 'none';
    }

    const value = Number(props.change);

    return value === 0 ? 'flat' : value > 0 ? 'up' : 'down';
});

const tone = computed(() => {
    if (direction.value === 'up' || direction.value === 'down') {
        const good = (direction.value === 'up') === props.goodWhenUp;

        return good
            ? 'text-emerald-700 dark:text-emerald-400'
            : 'text-danger-text';
    }

    return 'text-muted-foreground';
});
</script>

<template>
    <div class="flex flex-col rounded-xl border bg-card p-4 shadow-sm">
        <p class="text-sm text-muted-foreground">{{ title }}</p>

        <div class="mt-1 flex flex-wrap items-baseline gap-x-2">
            <div class="text-2xl font-semibold tabular-nums">
                <slot />
            </div>

            <span
                v-if="change !== null"
                class="inline-flex items-center gap-1 text-xs font-medium tabular-nums"
                :class="tone"
                :title="`Against the period before`"
            >
                <TrendingUp v-if="direction === 'up'" class="size-3.5" />
                <TrendingDown
                    v-else-if="direction === 'down'"
                    class="size-3.5"
                />
                {{ direction === 'flat' ? '0.0' : change }}%
            </span>
        </div>

        <p class="mt-1 min-h-4 text-xs text-muted-foreground">
            <slot name="hint" />
        </p>

        <Sparkline
            v-if="series"
            :values="series"
            class="mt-3 h-8 w-full text-primary-ink"
        />
    </div>
</template>
