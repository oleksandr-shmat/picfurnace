<script setup lang="ts">
import { computed } from 'vue';

/**
 * A tiny trend line, no axes and no numbers: only the shape of the values over time. It takes the
 * colour of the text around it.
 */
const props = defineProps<{ values: number[] }>();

const WIDTH = 100;
const HEIGHT = 28;
const PADDING = 2;

const points = computed(() => {
    const { values } = props;

    if (values.length === 0) {
        return [];
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min;

    return values.map((value, index) => ({
        x:
            values.length === 1
                ? WIDTH / 2
                : (index / (values.length - 1)) * WIDTH,
        // A line without any change runs through the middle.
        y:
            span === 0
                ? HEIGHT / 2
                : HEIGHT -
                  PADDING -
                  ((value - min) / span) * (HEIGHT - 2 * PADDING),
    }));
});

const line = computed(() =>
    points.value.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' '),
);

const area = computed(() =>
    points.value.length < 2
        ? ''
        : `M0,${HEIGHT} L${line.value.split(' ').join(' L')} L${WIDTH},${HEIGHT} Z`,
);
</script>

<template>
    <svg
        v-if="points.length > 0"
        :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
        preserveAspectRatio="none"
        class="overflow-visible"
        aria-hidden="true"
    >
        <path v-if="area" :d="area" fill="currentColor" fill-opacity="0.12" />
        <polyline
            :points="line"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linejoin="round"
            stroke-linecap="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
