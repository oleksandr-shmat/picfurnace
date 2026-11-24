<script setup lang="ts">
import { Download } from '@lucide/vue';
import ReportMoney from '@/components/admin/ReportMoney.vue';
import ReportTable from '@/components/admin/ReportTable.vue';
import { Button } from '@/components/ui/button';
import type {
    Direction,
    ReportLine,
    ReportTotals,
    TableColumn,
    TableMeta,
} from '@/types/report';

defineProps<{
    days: { rows: ReportLine[]; meta: TableMeta };
    /** The totals of the whole period, not of this page. */
    totals: ReportTotals;
    sort: string;
    direction: Direction;
    exportHref: string;
}>();

defineEmits<{ sort: [key: string]; page: [page: number] }>();

const columns: TableColumn[] = [
    { key: 'day', label: 'Day (UTC)', sortable: true },
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
];

// The amount a money column shows for a row.
const amountOf: Record<string, (row: ReportLine) => string> = {
    charged: (row) => row.charged,
    provider_cost: (row) => row.providerCost,
    profit: (row) => row.profit,
};
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">By day</h2>
                <p class="text-sm text-muted-foreground">
                    A generation counts on the day it completed.
                </p>
            </div>
            <Button variant="outline" size="sm" as-child>
                <a :href="exportHref" download> <Download /> Export CSV </a>
            </Button>
        </div>

        <ReportTable
            :columns="columns"
            :rows="days.rows"
            :sort="sort"
            :direction="direction"
            :meta="days.meta"
            empty="No generation succeeded in this period."
            @sort="$emit('sort', $event)"
            @page="$emit('page', $event)"
        >
            <template #cell="{ row, column }">
                <template v-if="column.key === 'day'">{{ row.key }}</template>
                <template v-else-if="column.key === 'generations'">
                    {{ row.generations }}
                </template>
                <ReportMoney v-else :amount="amountOf[column.key](row)" />
            </template>
            <template #footer>
                <tr>
                    <td class="p-3">Total of the period</td>
                    <td class="p-3 text-right">{{ totals.generations }}</td>
                    <td class="p-3 text-right">
                        <ReportMoney :amount="totals.charged" />
                    </td>
                    <td class="p-3 text-right">
                        <ReportMoney :amount="totals.providerCost" />
                    </td>
                    <td class="p-3 text-right">
                        <ReportMoney :amount="totals.profit" />
                    </td>
                </tr>
            </template>
        </ReportTable>
    </section>
</template>
