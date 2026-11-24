<script setup lang="ts">
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * What a generation looks like while it is being made: a small furnace. A glow breathes at the
 * bottom, a band of molten light sweeps across, embers rise, and the PicFurnace mark glows in the
 * middle. The further the generation has got, the hotter it burns: waiting for its turn is a banked
 * fire, queued at the image service is a steady one, drawing is a roaring one.
 */
const props = defineProps<{
    status: 'pending' | 'queued' | 'processing';
    label: string;
}>();

const heat = computed(
    () => ({ pending: 'low', queued: 'mid', processing: 'high' })[props.status],
);

const emberCount = computed(
    () => ({ pending: 5, queued: 9, processing: 16 })[props.status],
);

// Fixed-looking but varied: positions and timings come from the index, so every card looks the
// same on every visit and nothing is random between renders.
const embers = computed(() =>
    Array.from({ length: emberCount.value }, (_, i) => ({
        left: `${((i * 37 + 11) % 94) + 3}%`,
        size: `${2 + (i % 3)}px`,
        duration: `${3.2 + (i % 5) * 0.7}s`,
        delay: `-${((i * 0.83) % 4).toFixed(2)}s`,
        sway: `${(i % 2 === 0 ? 1 : -1) * (6 + (i % 4) * 4)}px`,
    })),
);
</script>

<template>
    <div class="furnace relative size-full overflow-hidden" :data-heat="heat">
        <div class="furnace-glow absolute inset-0" />
        <div class="furnace-sweep absolute inset-0" />

        <span
            v-for="(ember, index) in embers"
            :key="index"
            class="furnace-ember absolute bottom-0 rounded-full"
            :style="{
                left: ember.left,
                width: ember.size,
                height: ember.size,
                animationDuration: ember.duration,
                animationDelay: ember.delay,
                '--sway': ember.sway,
            }"
        />

        <div
            class="relative flex size-full flex-col items-center justify-center gap-3 p-4 text-center text-sm text-white/80"
        >
            <AppLogoIcon class="furnace-mark size-10 text-white/70" />
            <span class="drop-shadow">{{ label }}</span>
        </div>
    </div>
</template>

<style scoped>
.furnace {
    --heat-opacity: 0.55;
    --heat-speed: 4s;
    --embers: 0.55;
    background: oklch(0.15 0.02 45);
}
.furnace[data-heat='mid'] {
    --heat-opacity: 0.8;
    --heat-speed: 2.6s;
    --embers: 0.8;
}
.furnace[data-heat='high'] {
    --heat-opacity: 1;
    --heat-speed: 1.7s;
    --embers: 1;
}

/* The fire at the bottom, breathing. */
.furnace-glow {
    background:
        radial-gradient(
            ellipse 75% 60% at 50% 105%,
            rgb(255 140 40 / 0.65),
            transparent 70%
        ),
        radial-gradient(
            ellipse 40% 35% at 50% 100%,
            rgb(255 200 110 / 0.5),
            transparent 70%
        );
    opacity: var(--heat-opacity);
    transform-origin: 50% 100%;
    animation: furnace-breathe var(--heat-speed) ease-in-out infinite;
}

/* A band of molten light moving across. Not in the banked fire. */
.furnace-sweep {
    background: linear-gradient(
        105deg,
        transparent 35%,
        rgb(255 170 70 / 0.16) 50%,
        transparent 65%
    );
    transform: translateX(-100%);
    animation: furnace-sweep calc(var(--heat-speed) * 1.6) linear infinite;
}
.furnace[data-heat='low'] .furnace-sweep {
    display: none;
}

.furnace-ember {
    background: rgb(255 175 80);
    box-shadow: 0 0 6px 1px rgb(255 140 40 / 0.8);
    opacity: 0;
    animation-name: furnace-ember;
    animation-timing-function: ease-out;
    animation-iteration-count: infinite;
}

.furnace-mark {
    filter: drop-shadow(0 0 10px rgb(255 150 50 / 0.7));
    animation: furnace-pulse var(--heat-speed) ease-in-out infinite;
}

@keyframes furnace-breathe {
    0%,
    100% {
        opacity: calc(var(--heat-opacity) * 0.7);
        transform: scaleY(0.92);
    }
    50% {
        opacity: var(--heat-opacity);
        transform: scaleY(1.1);
    }
}

@keyframes furnace-sweep {
    to {
        transform: translateX(100%);
    }
}

@keyframes furnace-ember {
    0% {
        opacity: 0;
        transform: translate(0, 0) scale(1);
    }
    12% {
        opacity: var(--embers);
    }
    100% {
        opacity: 0;
        transform: translate(var(--sway), -230px) scale(0.3);
    }
}

@keyframes furnace-pulse {
    0%,
    100% {
        opacity: 0.55;
    }
    50% {
        opacity: 1;
    }
}

/* Nothing moves for people who asked for less motion: a still glow is left. */
@media (prefers-reduced-motion: reduce) {
    .furnace-glow,
    .furnace-sweep,
    .furnace-ember,
    .furnace-mark {
        animation: none;
    }
    .furnace-sweep,
    .furnace-ember {
        display: none;
    }
}
</style>
