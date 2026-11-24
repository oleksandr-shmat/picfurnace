<script setup lang="ts">
import { formatUsdFull, formatUsdRounded } from '@/lib/money';

/**
 * An amount of the report. In a table it is shown in full (nine decimals, the way Pixelforge
 * reports it, to compare with `GET /v1/usage`); with `short` it is rounded and the full amount is
 * on hover.
 */
defineProps<{ amount: string; short?: boolean }>();
</script>

<template>
    <span v-if="short" :title="formatUsdFull(amount)">{{
        formatUsdRounded(amount)
    }}</span>
    <span v-else>{{ formatUsdFull(amount) }}</span>
</template>
