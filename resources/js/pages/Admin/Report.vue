<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import DaysSection from '@/components/admin/DaysSection.vue';
import LedgerStatus from '@/components/admin/LedgerStatus.vue';
import ReportBreakdown from '@/components/admin/ReportBreakdown.vue';
import ReportFilters from '@/components/admin/ReportFilters.vue';
import ReportMetrics from '@/components/admin/ReportMetrics.vue';
import UsersSection from '@/components/admin/UsersSection.vue';
import Heading from '@/components/Heading.vue';
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
    LedgerStatus as Ledger,
    ReportFilters as Filters,
    ReportLine,
    ReportMetrics as Metrics,
    ReportRange,
    ReportTotals,
    TableMeta,
    UserOption,
} from '@/types/report';

const props = defineProps<{
    filters: Filters;
    range: ReportRange;
    userOptions: UserOption[];
    metrics: Metrics;
    totals: ReportTotals;
    breakdown: { quality: ReportLine[]; size: ReportLine[] };
    days: { rows: ReportLine[]; meta: TableMeta };
    users: { rows: ReportLine[]; meta: TableMeta };
    ledger: Ledger;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Report', href: report() }],
    },
});

const when = computed(() => periodLabel(props.range.from, props.range.to));
const before = computed(() =>
    previousPeriodLabel(props.range.from, props.range.days),
);

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
    return exportMethod.url({ query: { type, ...reportQuery(props.filters) } });
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

            <LedgerStatus :ledger="ledger" />
        </header>

        <ReportMetrics :metrics="metrics" />
        <ReportBreakdown :breakdown="breakdown" />

        <DaysSection
            :days="days"
            :totals="totals"
            :sort="filters.daysSort"
            :direction="filters.daysDir"
            :export-href="exportUrl('days')"
            @sort="sortDays"
            @page="go({ daysPage: $event, usersPage: users.meta.page })"
        />

        <UsersSection
            :users="users"
            :sort="filters.usersSort"
            :direction="filters.usersDir"
            :search="filters.search"
            :export-href="exportUrl('users')"
            @sort="sortUsers"
            @page="go({ usersPage: $event, daysPage: days.meta.page })"
            @update:search="go({ search: $event })"
            @pick="go({ user: $event })"
        />
    </div>
</template>
