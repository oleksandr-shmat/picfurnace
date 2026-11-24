<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CircleCheck, Download, Search, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MetricCard from '@/components/admin/MetricCard.vue';
import ReportFilters from '@/components/admin/ReportFilters.vue';
import ReportTable from '@/components/admin/ReportTable.vue';
import PriceDetails from '@/components/generations/PriceDetails.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatUsd, formatUsdRounded, toNano } from '@/lib/money';
import {
    nextSort,
    periodLabel,
    previousPeriodLabel,
    reportQuery,
} from '@/lib/reportQuery';
import type { ReportChange } from '@/lib/reportQuery';
import { report } from '@/routes/admin';
import { exportMethod } from '@/routes/admin/report';
import type {
    LedgerStatus,
    ReportFilters as Filters,
    ReportLine,
    ReportMetrics,
    ReportRange,
    ReportTotals,
    TableMeta,
    UserOption,
} from '@/types/report';

const props = defineProps<{
    filters: Filters;
    range: ReportRange;
    userOptions: UserOption[];
    metrics: ReportMetrics;
    totals: ReportTotals;
    breakdown: { quality: ReportLine[]; size: ReportLine[] };
    days: { rows: ReportLine[]; meta: TableMeta };
    users: { rows: ReportLine[]; meta: TableMeta };
    ledger: LedgerStatus;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: report() }],
    },
});

const dayColumns = [
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
] as const;

const userColumns = [
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
] as const;

const when = computed(() => periodLabel(props.range.from, props.range.to));
const before = computed(() =>
    previousPeriodLabel(props.range.from, props.range.days),
);

const dateTime = new Intl.DateTimeFormat('en', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
});

// Every change of the view is a visit with a new query string: the page keeps its scroll and the
// server does the filtering, sorting and paging.
function go(change: ReportChange) {
    router.get(report.url(), reportQuery(props.filters, change), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function sortDays(key: string) {
    const next = nextSort(props.filters.daysSort, props.filters.daysDir, key);
    go({ daysSort: next.sort, daysDir: next.direction });
}

function sortUsers(key: string) {
    const next = nextSort(props.filters.usersSort, props.filters.usersDir, key);
    go({ usersSort: next.sort, usersDir: next.direction });
}

function exportUrl(type: 'days' | 'users') {
    return exportMethod.url({
        query: { type, ...reportQuery(props.filters) },
    });
}

const search = ref(props.filters.search ?? '');
const searchNow = useDebounceFn(() => {
    if ((search.value || null) !== props.filters.search) {
        go({ search: search.value || null });
    }
}, 350);
watch(search, searchNow);

// How much of the revenue a line is, for the bars of the breakdown.
function share(line: ReportLine, lines: ReportLine[]): number {
    const total = lines.reduce((sum, item) => sum + toNano(item.charged), 0n);

    return total === 0n
        ? 0
        : Number((toNano(line.charged) * 1000n) / total) / 10;
}
</script>

<template>
    <Head title="Report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <header class="space-y-4">
            <Heading
                title="Report"
                description="What users were charged, what Pixelforge billed us and what we kept. Only succeeded generations count: a failed one is free."
            />

            <ReportFilters
                :filters="filters"
                :range="range"
                :user-options="userOptions"
                @change="go"
            />

            <p class="text-sm text-muted-foreground">
                <span class="font-medium text-foreground">{{ when }}</span>
                (UTC days)
                <template v-if="before">
                    · changes are against {{ before }}
                </template>
            </p>

            <Alert v-if="!ledger.agrees" variant="destructive">
                <TriangleAlert />
                <AlertTitle>The ledger and the generations disagree</AlertTitle>
                <AlertDescription>
                    The generations say users were charged
                    {{ formatUsd(ledger.onGenerations) }}, the credit ledger
                    says {{ formatUsd(ledger.inLedger) }}. Money was moved
                    around the ledger and needs a look.
                </AlertDescription>
            </Alert>
            <p
                v-else
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <CircleCheck
                    class="size-3.5 text-emerald-600 dark:text-emerald-400"
                />
                The credit ledger agrees with the charges on the generations.
            </p>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <MetricCard
                title="Revenue"
                :change="metrics.revenue.change"
                :series="metrics.revenue.series.map(Number)"
            >
                <PriceDetails
                    title="Revenue"
                    :rows="[
                        {
                            label: 'Exact',
                            amount: String(metrics.revenue.value),
                            strong: true,
                        },
                    ]"
                >
                    {{ formatUsdRounded(String(metrics.revenue.value)) }}
                </PriceDetails>
                <template #hint>What users were charged</template>
            </MetricCard>

            <MetricCard
                title="Pixelforge cost"
                :change="metrics.cost.change"
                :good-when-up="false"
                :series="metrics.cost.series.map(Number)"
            >
                <PriceDetails
                    title="Pixelforge cost"
                    :rows="[
                        {
                            label: 'Exact',
                            amount: String(metrics.cost.value),
                            strong: true,
                        },
                    ]"
                >
                    {{ formatUsdRounded(String(metrics.cost.value)) }}
                </PriceDetails>
                <template #hint>What Pixelforge billed us</template>
            </MetricCard>

            <MetricCard
                title="Profit"
                :change="metrics.profit.change"
                :series="metrics.profit.series.map(Number)"
            >
                <PriceDetails
                    title="Profit"
                    :rows="[
                        {
                            label: 'Exact',
                            amount: String(metrics.profit.value),
                            strong: true,
                        },
                    ]"
                >
                    {{ formatUsdRounded(String(metrics.profit.value)) }}
                </PriceDetails>
                <template #hint>
                    <template v-if="metrics.profit.margin !== null">
                        {{ metrics.profit.margin }}% margin
                    </template>
                    <template v-else>No revenue yet</template>
                </template>
            </MetricCard>

            <MetricCard
                title="Generations"
                :change="metrics.generations.change"
                :series="metrics.generations.series"
            >
                {{ metrics.generations.value }}
                <template #hint>
                    <template v-if="metrics.generations.successRate !== null">
                        {{ metrics.generations.successRate }}% succeeded ·
                    </template>
                    {{ metrics.generations.failed }} failed (free)
                </template>
            </MetricCard>

            <MetricCard
                title="Average price"
                :change="metrics.averagePrice.change"
            >
                <template v-if="metrics.averagePrice.value !== null">
                    <PriceDetails
                        title="Average price"
                        :rows="[
                            {
                                label: 'Exact',
                                amount: String(metrics.averagePrice.value),
                                strong: true,
                            },
                        ]"
                    >
                        {{
                            formatUsdRounded(String(metrics.averagePrice.value))
                        }}
                    </PriceDetails>
                </template>
                <span v-else class="text-muted-foreground">–</span>
                <template #hint>Per succeeded generation</template>
            </MetricCard>

            <MetricCard title="Credit sold" :change="metrics.credit.change">
                <PriceDetails
                    title="Credit sold"
                    :rows="[
                        {
                            label: 'Bought in the period',
                            amount: metrics.credit.sold,
                            strong: true,
                        },
                        {
                            label: 'Held by users now',
                            amount: metrics.credit.held,
                        },
                    ]"
                >
                    {{ formatUsdRounded(metrics.credit.sold) }}
                </PriceDetails>
                <template #hint>
                    {{ formatUsdRounded(metrics.credit.held) }} still held by
                    users
                </template>
            </MetricCard>
        </section>

        <section class="grid gap-4 md:grid-cols-2">
            <div
                v-for="group in [
                    { title: 'By quality', lines: breakdown.quality },
                    { title: 'By size', lines: breakdown.size },
                ]"
                :key="group.title"
                class="rounded-xl border bg-card p-4 shadow-sm"
            >
                <h2 class="mb-3 text-sm font-medium">{{ group.title }}</h2>

                <p
                    v-if="group.lines.length === 0"
                    class="py-4 text-center text-sm text-muted-foreground"
                >
                    Nothing in this period.
                </p>

                <ul v-else class="space-y-3">
                    <li
                        v-for="line in group.lines"
                        :key="line.key"
                        class="space-y-1"
                    >
                        <div
                            class="flex items-baseline justify-between gap-3 text-sm tabular-nums"
                        >
                            <span class="font-medium">{{ line.key }}</span>
                            <span class="text-muted-foreground">
                                {{ line.generations }}
                                {{
                                    line.generations === 1
                                        ? 'generation'
                                        : 'generations'
                                }}
                                ·
                                <span
                                    class="text-foreground"
                                    :title="formatUsd(line.charged)"
                                >
                                    {{ formatUsdRounded(line.charged) }}
                                </span>
                            </span>
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{
                                    width: `${share(line, group.lines)}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">By day</h2>
                    <p class="text-sm text-muted-foreground">
                        A generation counts on the day it completed. The
                        Pixelforge cost of a day should match
                        <code>GET /v1/usage</code> for that day.
                    </p>
                </div>
                <Button variant="outline" size="sm" as-child>
                    <a :href="exportUrl('days')" download>
                        <Download /> Export CSV
                    </a>
                </Button>
            </div>

            <ReportTable
                :columns="[...dayColumns]"
                :rows="days.rows"
                :sort="filters.daysSort"
                :direction="filters.daysDir"
                :meta="days.meta"
                empty="No generation succeeded in this period."
                @sort="sortDays"
                @page="go({ daysPage: $event, usersPage: users.meta.page })"
            >
                <template #cell="{ row, column }">
                    <template v-if="column.key === 'day'">{{
                        row.key
                    }}</template>
                    <template v-else-if="column.key === 'generations'">
                        {{ row.generations }}
                    </template>
                    <span
                        v-else
                        :title="
                            formatUsd(
                                column.key === 'charged'
                                    ? row.charged
                                    : column.key === 'provider_cost'
                                      ? row.providerCost
                                      : row.profit,
                            )
                        "
                    >
                        {{
                            formatUsdRounded(
                                column.key === 'charged'
                                    ? row.charged
                                    : column.key === 'provider_cost'
                                      ? row.providerCost
                                      : row.profit,
                            )
                        }}
                    </span>
                </template>
                <template #footer>
                    <tr>
                        <td class="p-3">Total of the period</td>
                        <td class="p-3 text-right">{{ totals.generations }}</td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsd(totals.charged)"
                        >
                            {{ formatUsdRounded(totals.charged) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsd(totals.providerCost)"
                        >
                            {{ formatUsdRounded(totals.providerCost) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsd(totals.profit)"
                        >
                            {{ formatUsdRounded(totals.profit) }}
                        </td>
                    </tr>
                </template>
            </ReportTable>
        </section>

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
                            v-model="search"
                            type="search"
                            placeholder="Search name or e-mail"
                            class="w-64 pl-8"
                            aria-label="Search users"
                        />
                    </div>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="exportUrl('users')" download>
                            <Download /> Export CSV
                        </a>
                    </Button>
                </div>
            </div>

            <ReportTable
                :columns="[...userColumns]"
                :rows="users.rows"
                :sort="filters.usersSort"
                :direction="filters.usersDir"
                :meta="users.meta"
                :empty="
                    filters.search
                        ? 'Nobody matches this search.'
                        : 'No user had a generation succeed in this period.'
                "
                @sort="sortUsers"
                @page="go({ usersPage: $event, daysPage: days.meta.page })"
            >
                <template #cell="{ row, column }">
                    <button
                        v-if="column.key === 'user'"
                        type="button"
                        class="text-left hover:underline"
                        :title="`Look at ${row.label} only`"
                        @click="go({ user: Number(row.key) })"
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
                    <span
                        v-else
                        :title="
                            formatUsd(
                                column.key === 'charged'
                                    ? row.charged
                                    : column.key === 'provider_cost'
                                      ? row.providerCost
                                      : column.key === 'profit'
                                        ? row.profit
                                        : (row.balance ?? '0'),
                            )
                        "
                    >
                        {{
                            formatUsdRounded(
                                column.key === 'charged'
                                    ? row.charged
                                    : column.key === 'provider_cost'
                                      ? row.providerCost
                                      : column.key === 'profit'
                                        ? row.profit
                                        : (row.balance ?? '0'),
                            )
                        }}
                    </span>
                </template>
            </ReportTable>
        </section>
    </div>
</template>
