<script setup lang="ts">
import { Check, Clock, Hourglass, TriangleAlert, Ban } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types/generations';

const props = defineProps<{
    status: GenerationStatus;
    cancelled: boolean;
}>();

const label = computed(() => {
    if (props.cancelled) {
        return 'Cancelled';
    }

    return {
        pending: 'In line',
        queued: 'Queued',
        processing: 'Generating',
        succeeded: 'Done',
        failed: 'Failed',
    }[props.status];
});
</script>

<template>
    <Badge
        :variant="
            status === 'failed' && !cancelled
                ? 'destructive'
                : status === 'succeeded'
                  ? 'default'
                  : 'secondary'
        "
    >
        <Ban v-if="cancelled" />
        <Clock v-else-if="status === 'pending'" />
        <Hourglass v-else-if="status === 'queued'" />
        <Spinner v-else-if="status === 'processing'" />
        <Check v-else-if="status === 'succeeded'" />
        <TriangleAlert v-else />
        {{ label }}
    </Badge>
</template>
