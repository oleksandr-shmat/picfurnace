<script setup lang="ts">
import ReportMoney from '@/components/admin/ReportMoney.vue';
import { toNano } from '@/lib/money';
import type { ReportLine } from '@/types/report';

defineProps<{ breakdown: { quality: ReportLine[]; size: ReportLine[] } }>();

// How much of the revenue a line is, for its bar (in tenths of a percent, so no float sums).
function share(line: ReportLine, lines: ReportLine[]): number {
    const total = lines.reduce((sum, item) => sum + toNano(item.charged), 0n);

    return total === 0n
        ? 0
        : Number((toNano(line.charged) * 1000n) / total) / 10;
}
</script>

<template>
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
                            <ReportMoney
                                class="text-foreground"
                                :amount="line.charged"
                                short
                            />
                        </span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary"
                            :style="{ width: `${share(line, group.lines)}%` }"
                        />
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
