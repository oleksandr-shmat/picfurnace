<script setup lang="ts">
import { formatUsd, formatUsdRounded } from '@/lib/money';

/** An amount of the report: short, with the exact amount when the pointer rests on it. */
defineProps<{ amount: string }>();
</script>

<template>
    <span :title="formatUsd(amount)">{{ formatUsdRounded(amount) }}</span>
</template>
