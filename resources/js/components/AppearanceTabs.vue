<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <!-- A segmented control in the colours of the app: the chosen option lifts off the track and
         its icon takes the amber accent. -->
    <div
        class="inline-flex gap-1 rounded-lg border bg-muted p-1"
        role="radiogroup"
        aria-label="Colour theme"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            role="radio"
            :aria-checked="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                'flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                appearance === value
                    ? 'bg-card font-medium text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
        >
            <component
                :is="Icon"
                :class="['size-4', { 'text-primary': appearance === value }]"
            />
            {{ label }}
        </button>
    </div>
</template>
