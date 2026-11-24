<script setup lang="ts" generic="Row extends { key: string }">
import { ChevronDown, ChevronUp, ChevronsUpDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { rowRange } from '@/lib/reportQuery';
import type { Direction, TableMeta } from '@/types/report';

type Column = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    sortable?: boolean;
};

defineProps<{
    columns: Column[];
    rows: Row[];
    sort: string;
    direction: Direction;
    meta: TableMeta;
    empty: string;
}>();

const emit = defineEmits<{ sort: [key: string]; page: [page: number] }>();
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
                <thead class="border-b text-muted-foreground">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="p-3 font-medium whitespace-nowrap"
                            :class="
                                column.align === 'right'
                                    ? 'text-right'
                                    : 'text-left'
                            "
                            :aria-sort="
                                column.key === sort
                                    ? direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-sm outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                :class="{
                                    'text-foreground': column.key === sort,
                                }"
                                @click="emit('sort', column.key)"
                            >
                                {{ column.label }}
                                <ChevronUp
                                    v-if="
                                        column.key === sort &&
                                        direction === 'asc'
                                    "
                                    class="size-3.5"
                                />
                                <ChevronDown
                                    v-else-if="column.key === sort"
                                    class="size-3.5"
                                />
                                <ChevronsUpDown
                                    v-else
                                    class="size-3.5 opacity-40"
                                />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="columns.length"
                            class="p-10 text-center text-muted-foreground"
                        >
                            {{ empty }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.key"
                        class="border-b last:border-0 hover:bg-muted/40"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="p-3"
                            :class="
                                column.align === 'right'
                                    ? 'text-right'
                                    : 'text-left'
                            "
                        >
                            <slot name="cell" :row="row" :column="column" />
                        </td>
                    </tr>
                </tbody>

                <tfoot
                    v-if="$slots.footer && rows.length > 0"
                    class="border-t bg-muted/40 font-medium"
                >
                    <slot name="footer" />
                </tfoot>
            </table>
        </div>

        <div
            class="flex items-center justify-between gap-3 border-t px-4 py-3 text-sm text-muted-foreground"
        >
            <span>{{ rowRange(meta) }}</span>

            <div v-if="meta.lastPage > 1" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.page <= 1"
                    @click="emit('page', meta.page - 1)"
                >
                    Previous
                </Button>
                <span class="tabular-nums">
                    Page {{ meta.page }} of {{ meta.lastPage }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.page >= meta.lastPage"
                    @click="emit('page', meta.page + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
