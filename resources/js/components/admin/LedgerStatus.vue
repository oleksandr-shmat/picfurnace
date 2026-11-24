<script setup lang="ts">
import { CircleCheck, TriangleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { formatUsd } from '@/lib/money';
import type { LedgerStatus } from '@/types/report';

/** The charges in the ledger must equal the charges on the generations: an alert when they do not. */
defineProps<{ ledger: LedgerStatus }>();
</script>

<template>
    <Alert v-if="!ledger.agrees" variant="destructive">
        <TriangleAlert />
        <AlertTitle>The ledger and the generations disagree</AlertTitle>
        <AlertDescription>
            The generations say users were charged
            {{ formatUsd(ledger.onGenerations) }}, the credit ledger says
            {{ formatUsd(ledger.inLedger) }}. Money was moved around the ledger
            and needs a look.
        </AlertDescription>
    </Alert>
    <p v-else class="flex items-center gap-1.5 text-xs text-muted-foreground">
        <CircleCheck class="size-3.5 text-emerald-600 dark:text-emerald-400" />
        The credit ledger agrees with the charges on the generations.
    </p>
</template>
