<script setup lang="ts">
import PriceDetails from '@/components/generations/PriceDetails.vue';
import { formatUsdRounded } from '@/lib/money';

/** The big number of a metric: short, and a click opens the exact amount (or the given rows). */
withDefaults(
    defineProps<{
        title: string;
        amount: string;
        rows?: { label: string; amount: string; strong?: boolean }[];
    }>(),
    { rows: undefined },
);
</script>

<template>
    <PriceDetails
        :title="title"
        :rows="rows ?? [{ label: 'Exact', amount, strong: true }]"
    >
        {{ formatUsdRounded(amount) }}
    </PriceDetails>
</template>
