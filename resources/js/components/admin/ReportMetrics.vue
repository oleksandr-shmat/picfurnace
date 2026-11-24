<script setup lang="ts">
import MetricCard from '@/components/admin/MetricCard.vue';
import MoneyFigure from '@/components/admin/MoneyFigure.vue';
import { formatUsdRounded } from '@/lib/money';
import type { ReportMetrics } from '@/types/report';

defineProps<{ metrics: ReportMetrics }>();
</script>

<template>
    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <MetricCard
            title="Revenue"
            :change="metrics.revenue.change"
            :series="metrics.revenue.series.map(Number)"
        >
            <MoneyFigure title="Revenue" :amount="metrics.revenue.value" />
            <template #hint>What users were charged</template>
        </MetricCard>

        <MetricCard
            title="Pixelforge cost"
            :change="metrics.cost.change"
            :good-when-up="false"
            :series="metrics.cost.series.map(Number)"
        >
            <MoneyFigure title="Pixelforge cost" :amount="metrics.cost.value" />
            <template #hint>What Pixelforge billed us</template>
        </MetricCard>

        <MetricCard
            title="Profit"
            :change="metrics.profit.change"
            :series="metrics.profit.series.map(Number)"
        >
            <MoneyFigure title="Profit" :amount="metrics.profit.value" />
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

        <MetricCard title="Average price" :change="metrics.averagePrice.change">
            <MoneyFigure
                v-if="metrics.averagePrice.value !== null"
                title="Average price"
                :amount="metrics.averagePrice.value"
            />
            <span v-else class="text-muted-foreground">–</span>
            <template #hint>Per succeeded generation</template>
        </MetricCard>

        <MetricCard title="Credit sold" :change="metrics.credit.change">
            <MoneyFigure
                title="Credit sold"
                :amount="metrics.credit.sold"
                :rows="[
                    {
                        label: 'Bought in the period',
                        amount: metrics.credit.sold,
                        strong: true,
                    },
                    { label: 'Held by users now', amount: metrics.credit.held },
                ]"
            />
            <template #hint>
                {{ formatUsdRounded(metrics.credit.held) }} still held by users
            </template>
        </MetricCard>
    </section>
</template>
