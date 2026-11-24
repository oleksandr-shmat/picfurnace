<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { Download, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import ReportMoney from '@/components/admin/ReportMoney.vue';
import ReportTable from '@/components/admin/ReportTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    Direction,
    ReportLine,
    TableColumn,
    TableMeta,
} from '@/types/report';

const props = defineProps<{
    users: { rows: ReportLine[]; meta: TableMeta };
    sort: string;
    direction: Direction;
    /** The search that is applied now. */
    search: string | null;
    exportHref: string;
}>();

const emit = defineEmits<{
    sort: [key: string];
    page: [page: number];
    /** The search text changed (null when it was cleared). */
    'update:search': [search: string | null];
    /** The user to look at only. */
    pick: [userId: number];
}>();

const columns: TableColumn[] = [
    { key: 'user', label: 'User', sortable: true },
    {
        key: 'generations',
        label: 'Generations',
        align: 'right',
        sortable: true,
    },
    { key: 'charged', label: 'Charged', align: 'right', sortable: true },
    {
        key: 'provider_cost',
        label: 'Pixelforge cost',
        align: 'right',
        sortable: true,
    },
    { key: 'profit', label: 'Profit', align: 'right', sortable: true },
    {
        key: 'last_activity',
        label: 'Last finished',
        align: 'right',
        sortable: true,
    },
    { key: 'balance', label: 'Credit', align: 'right', sortable: true },
];

const amountOf: Record<string, (row: ReportLine) => string> = {
    charged: (row) => row.charged,
    provider_cost: (row) => row.providerCost,
    profit: (row) => row.profit,
    balance: (row) => row.balance ?? '0',
};

const dateTime = new Intl.DateTimeFormat('en', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
});

// The search runs on the server: wait until the typing pauses.
const text = ref(props.search ?? '');
const searchNow = useDebounceFn(() => {
    if ((text.value || null) !== props.search) {
        emit('update:search', text.value || null);
    }
}, 350);
watch(text, searchNow);
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">By user</h2>
                <p class="text-sm text-muted-foreground">
                    Users with a succeeded generation in the period. Click a
                    name to look at that user only.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="text"
                        type="search"
                        placeholder="Search name or e-mail"
                        class="w-64 pl-8"
                        aria-label="Search users"
                    />
                </div>
                <Button variant="outline" size="sm" as-child>
                    <a :href="exportHref" download> <Download /> Export CSV </a>
                </Button>
            </div>
        </div>

        <ReportTable
            :columns="columns"
            :rows="users.rows"
            :sort="sort"
            :direction="direction"
            :meta="users.meta"
            :empty="
                search
                    ? 'Nobody matches this search.'
                    : 'No user had a generation succeed in this period.'
            "
            @sort="$emit('sort', $event)"
            @page="$emit('page', $event)"
        >
            <template #cell="{ row, column }">
                <button
                    v-if="column.key === 'user'"
                    type="button"
                    class="text-left hover:underline"
                    :title="`Look at ${row.label} only`"
                    @click="$emit('pick', Number(row.key))"
                >
                    {{ row.label }}
                </button>
                <template v-else-if="column.key === 'generations'">
                    {{ row.generations }}
                </template>
                <template v-else-if="column.key === 'last_activity'">
                    {{
                        row.lastActivity
                            ? dateTime.format(new Date(row.lastActivity))
                            : '–'
                    }}
                </template>
                <ReportMoney v-else :amount="amountOf[column.key](row)" />
            </template>
        </ReportTable>
    </section>
</template>
