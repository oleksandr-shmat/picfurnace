<script setup lang="ts">
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatUsd } from '@/lib/money';

defineProps<{
    title: string;
    /** Exact decimal strings from the server. */
    rows: { label: string; amount: string; strong?: boolean }[];
}>();
</script>

<template>
    <!-- A price is shown short (the default slot); clicking it opens the exact amounts above the
         page, without moving anything. The dashed underline and the "help" cursor say it can be
         clicked. -->
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="cursor-help text-left underline decoration-muted-foreground/60 decoration-dashed underline-offset-[5px] hover:decoration-foreground"
                :aria-label="title"
            >
                <slot />
            </button>
        </PopoverTrigger>

        <PopoverContent align="start" class="space-y-3 text-sm">
            <p class="font-medium">{{ title }}</p>

            <dl class="space-y-2 tabular-nums">
                <div
                    v-for="row in rows"
                    :key="row.label"
                    class="flex justify-between gap-4"
                >
                    <dt class="text-muted-foreground">{{ row.label }}</dt>
                    <dd :class="{ 'font-medium': row.strong }">
                        {{ formatUsd(row.amount) }}
                    </dd>
                </div>
            </dl>

            <slot name="note" />
        </PopoverContent>
    </Popover>
</template>
